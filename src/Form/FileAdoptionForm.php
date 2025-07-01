<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file_adoption\Util\UriHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Configuration form for the File Adoption module.
 */
class FileAdoptionForm extends ConfigFormBase {

  /**
   * The file scanner service.
   *
   * @var \Drupal\file_adoption\FileScanner
  */
  protected $fileScanner;

  /**
   * Inventory manager service.
   *
   * @var \Drupal\file_adoption\InventoryManager
   */
  protected $inventoryManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Temporary storage for batch results.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;


  /**
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   */
  public function __construct(FileScanner $fileScanner, \Drupal\file_adoption\InventoryManager $inventoryManager, FileSystemInterface $fileSystem, PrivateTempStoreFactory $tempStoreFactory) {
    $this->fileScanner = $fileScanner;
    $this->inventoryManager = $inventoryManager;
    $this->fileSystem = $fileSystem;
    $this->tempStore = $tempStoreFactory->get('file_adoption');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_adoption.inventory_manager'),
      $container->get('file_system'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'file_adoption_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['file_adoption.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('file_adoption.settings');

    $show_preview = TRUE;

    $form['ignore_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ignore Patterns'),
      '#default_value' => $config->get('ignore_patterns'),
      '#description' => $this->t('File paths (relative to public://) to ignore when scanning. Separate multiple patterns with commas or new lines.'),
    ];

    $form['enable_adoption'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Adoption'),
      '#default_value' => $config->get('enable_adoption'),
      '#description' => $this->t('If checked, orphaned files will be adopted automatically during cron runs.'),
    ];

    $form['follow_symlinks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Follow symbolic links'),
      '#default_value' => $config->get('follow_symlinks'),
      '#description' => $this->t('Include files found via symbolic links when scanning.'),
    ];

    $items_per_run = $config->get('items_per_run');
    if (empty($items_per_run)) {
      $items_per_run = 20;
    }
    $form['items_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per cron run'),
      '#default_value' => $items_per_run,
      '#min' => 1,
    ];

    $status_filter = $form_state->getValue('status_filter') ?? 'all';
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => TRUE,
    ];
    $form['filters']['status_filter'] = [
      '#type' => 'radios',
      '#title' => $this->t('Show'),
      '#options' => [
        'all' => $this->t('All tracked files'),
        'ignored' => $this->t('Ignored files'),
        'unmanaged' => $this->t('Unmanaged files'),
      ],
      '#default_value' => $status_filter,
    ];
    $form['filters']['apply_filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filter'),
      '#name' => 'apply_filter',
    ];

    $ignored = ($status_filter === 'ignored');
    $unmanaged = ($status_filter === 'unmanaged');
    $count = $this->inventoryManager->countFiles($ignored, $unmanaged);
    $files = $this->inventoryManager->listFiles($ignored, $unmanaged, 20);

    $new_orphans = $this->tempStore->get('scan_orphans') ?? [];

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $count ? $this->t('Tracked Files (@count)', ['@count' => $count]) : $this->t('Tracked Files'),
      '#open' => TRUE,
    ];
    $markup = '';
    if ($new_orphans) {
      $markup .= '<h4>' . $this->t('Orphan files') . '</h4>';
      $markup .= '<ul><li>' . implode('</li><li>', array_map([Html::class, 'escape'], $new_orphans)) . '</li></ul>';
    }
    if ($count) {
      $markup .= '<h4>' . $this->t('Tracked files') . '</h4>';
      $markup .= '<ul><li>' . implode('</li><li>', array_map([Html::class, 'escape'], $files)) . '</li></ul>';
      if ($count > count($files)) {
        $markup .= '<p>' . $this->formatPlural($count - count($files), '@count additional file not shown', '@count additional files not shown') . '</p>';
      }
    }
    if ($markup === '') {
      $markup = $this->t('Run a scan to generate a preview.');
    }
    $form['preview']['markup'] = [
      '#markup' => Markup::create($markup),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Configuration'),
      '#button_type' => 'primary',
    ];
    $form['actions']['scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Scan'),
      '#button_type' => 'secondary',
      '#name' => 'scan',
    ];
    $form['actions']['adopt'] = [
      '#type' => 'submit',
      '#value' => $this->t('Adopt'),
      '#button_type' => 'secondary',
      '#name' => 'adopt',
    ];
    $form['actions']['cleanup'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cleanup'),
      '#button_type' => 'secondary',
      '#name' => 'cleanup',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $items_per_run = (int) $form_state->getValue('items_per_run');
    if ($items_per_run <= 0) {
      $items_per_run = 20;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('follow_symlinks', $form_state->getValue('follow_symlinks'))
      ->set('items_per_run', $items_per_run)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';

    if ($trigger === 'apply_filter') {
      $form_state->setRebuild(TRUE);
      return;
    }

    if ($trigger === 'scan') {
      $batch = [
        'title' => $this->t('Scanning files'),
        'operations' => [
          [[static::class, 'batchScan'], [100]],
        ],
        'finished' => [static::class, 'batchFinished'],
      ];
      batch_set($batch);
    }
    elseif ($trigger === 'adopt') {
      $batch = [
        'title' => $this->t('Adopting files'),
        'operations' => [
          ['\\Drupal\\file_adoption\\InventoryManager::batchAdopt', [$items_per_run]],
        ],
        'finished' => ['\\Drupal\\file_adoption\\InventoryManager::adoptFinished'],
      ];
      batch_set($batch);
    }
    elseif ($trigger === 'cleanup') {
      $batch = [
        'title' => $this->t('Cleaning up records'),
        'operations' => [
          ['\\Drupal\\file_adoption\\InventoryManager::batchPurge', []],
        ],
        'finished' => ['\\Drupal\\file_adoption\\InventoryManager::purgeFinished'],
      ];
      batch_set($batch);
    }
    else {
      $this->messenger()->addStatus($this->t('Configuration saved.'));
    }
  }

  /**
   * Batch operation callback for scanning files.
   */
  public static function batchScan(int $limit, array &$context) {
    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = \Drupal::service('file_adoption.file_scanner');

    if (!isset($context['sandbox']['offset'])) {
      // Initialize the sandbox without performing an expensive file count.
      $context['sandbox']['offset'] = 0;
      $context['sandbox']['processed'] = 0;
      // Estimate the total files to scan based on managed files in the database
      // with a 5% buffer to account for new files discovered during the scan.
      $managed = $scanner->countManagedFiles();
      $context['sandbox']['total'] = (int) round($managed * 1.05);
      $context['results'] = [
        'files' => 0,
        'orphans' => 0,
        'to_manage' => [],
      ];
    }

    $chunk = $scanner->scanChunk($context['sandbox']['offset'], $limit);
    $context['sandbox']['offset'] = $chunk['offset'];
    $context['sandbox']['processed'] += $chunk['results']['files'];
    $context['results']['files'] += $chunk['results']['files'];
    $context['results']['orphans'] += $chunk['results']['orphans'];
    $context['results']['to_manage'] = array_merge($context['results']['to_manage'], $chunk['results']['to_manage']);

    // Persist discovered orphans so the form can display them after the batch.
    \Drupal::service('tempstore.private')
      ->get('file_adoption')
      ->set('scan_orphans', $context['results']['to_manage']);

    // When no files are returned the scan is complete.
    if ($chunk['results']['files'] === 0) {
      $context['finished'] = 1;
    }
    else {
      $total = max(1, $context['sandbox']['total']);
      $context['finished'] = min(1, $context['sandbox']['processed'] / $total);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations) {
    $store = \Drupal::service('tempstore.private')->get('file_adoption');
    $store->delete('scan_orphans');

    if ($success) {
      \Drupal::messenger()->addStatus(\Drupal::translation()->translate('Scan complete: @files file(s) scanned and @orphans orphan(s) found.', [
        '@files' => $results['files'],
        '@orphans' => $results['orphans'],
      ]));
    }
    else {
      \Drupal::messenger()->addError(\Drupal::translation()->translate('Scan failed.'));
    }
  }

}
