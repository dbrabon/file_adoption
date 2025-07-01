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
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   */
  public function __construct(FileScanner $fileScanner, \Drupal\file_adoption\InventoryManager $inventoryManager, FileSystemInterface $fileSystem) {
    $this->fileScanner = $fileScanner;
    $this->inventoryManager = $inventoryManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_adoption.inventory_manager'),
      $container->get('file_system')
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


    $form['ignore_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ignore Patterns'),
      '#default_value' => $config->get('ignore_patterns'),
      '#description' => $this->t('File and directory paths (relative to public://) to ignore when scanning. Matching directories are listed under "Ignored directories". Separate patterns with commas or new lines.'),
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

    $form['scan_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum scan depth'),
      '#default_value' => $config->get('scan_depth') ?? 0,
      '#min' => 0,
      '#description' => $this->t('Limit directory traversal to this depth. Use 0 for unlimited.'),
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

    $count = $this->inventoryManager->countFiles();
    $files = $this->inventoryManager->listFiles(FALSE, FALSE, 20);

    $new_orphans = \Drupal::service('tempstore.private')
      ->get('file_adoption')
      ->get('scan_orphans') ?? [];

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

    if ($count || $new_orphans) {
      $markup .= '<p><em>' . $this->t('Results reflect the last completed scan; run a scan to refresh.') . '</em></p>';
    }

    if ($markup === '') {
      $markup = $this->t('Run a scan to generate a preview.');
    }
    $form['preview']['markup'] = [
      '#markup' => Markup::create($markup),
    ];

    $dir_count = $this->inventoryManager->countDirs();
    $dir_list = $dir_count ? $this->inventoryManager->listDirSummaries(20) : [];

    $dir_markup = '';
    if ($dir_list) {
      $dir_markup .= '<ul>';
      foreach ($dir_list as $info) {
        $label = $info['uri'];
        if (!empty($info['example'])) {
          $label .= ' (e.g., ' . $info['example'] . ')';
        }
        if ($info['count'] > 0) {
          $label .= ' (' . $info['count'] . ')';
        }
        $escaped = Html::escape($label);
        if ($info['ignored']) {
          $dir_markup .= '<li><span style="color:gray">' . $escaped . '</span></li>';
        }
        else {
          $dir_markup .= '<li>' . $escaped . '</li>';
        }
      }
      $dir_markup .= '</ul>';
      if ($dir_count > count($dir_list)) {
        $dir_markup .= '<p>' . $this->formatPlural($dir_count - count($dir_list), '@count additional directory not shown', '@count additional directories not shown') . '</p>';
      }
    }
    else {
      $dir_markup = $this->t('Run a scan to generate a preview.');
    }

    $form['dir_preview'] = [
      '#type' => 'details',
      '#title' => $dir_count ? $this->t('Directory Preview (@count)', ['@count' => $dir_count]) : $this->t('Directory Preview'),
      '#open' => TRUE,
    ];
    $form['dir_preview']['markup'] = [
      '#markup' => Markup::create($dir_markup),
    ];

    $unmanaged_count = $this->inventoryManager->countFiles(FALSE, TRUE);
    $unmanaged_list = $unmanaged_count ? $this->inventoryManager->listUnmanagedById($items_per_run) : [];
    $form['adopt_preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Add to Managed Files'),
      '#open' => TRUE,
    ];
    $adopt_markup = '';
    if ($unmanaged_count) {
      $adopt_markup .= '<p>' . $this->formatPlural($unmanaged_count, '@count file ready for adoption', '@count files ready for adoption') . '</p>';
      if ($unmanaged_list) {
        $adopt_markup .= '<ul><li>' . implode('</li><li>', array_map([Html::class, 'escape'], $unmanaged_list)) . '</li></ul>';
      }
    }
    else {
      $adopt_markup = $this->t('No unmanaged files found.');
    }
    $form['adopt_preview']['markup'] = [
      '#markup' => Markup::create($adopt_markup),
    ];
    $form['adopt_preview']['adopt'] = [
      '#type' => 'submit',
      '#value' => $this->t('Adopt now'),
      '#name' => 'adopt',
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
      ->set('scan_depth', (int) $form_state->getValue('scan_depth'))
      ->set('items_per_run', $items_per_run)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';


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
