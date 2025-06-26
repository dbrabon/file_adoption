<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * State service for persisting scan progress and results.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;


  /**
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   */
  public function __construct(FileScanner $fileScanner, FileSystemInterface $fileSystem, StateInterface $state) {
    $this->fileScanner = $fileScanner;
    $this->fileSystem = $fileSystem;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_system'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'file_adoption_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['file_adoption.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('file_adoption.settings');

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

    $items_per_run = $config->get('items_per_run');
    if (empty($items_per_run)) {
      $items_per_run = 100;
    }
    elseif ($items_per_run > 5000) {
      $items_per_run = 5000;
    }
    $form['items_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per cron run'),
      '#default_value' => $items_per_run,
      '#min' => 1,
      '#max' => 5000,
    ];


    $preview_ready = $form_state->get('scan_results') || $this->state->get('file_adoption.scan_results');

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Directory Contents Preview'),
      '#open' => $preview_ready ? TRUE : FALSE,
      '#attributes' => ['id' => 'file-adoption-preview-wrapper'],
    ];
    if ($preview_ready) {
      $form['preview']['markup'] = [
        '#markup' => Markup::create('<div id="file-adoption-preview">' . $this->t('Building preview…') . '</div>'),
      ];
      $form['#attached']['library'][] = 'file_adoption/preview';
      $form['#attached']['drupalSettings']['file_adoption']['preview_url'] = Url::fromRoute('file_adoption.preview_ajax')->toString();
      $form['#attached']['drupalSettings']['file_adoption']['preview_title'] = $this->t('Public Directory Contents Preview');
    }
    else {
      $form['preview']['#description'] = $this->t('Run a quick scan or batch scan to view a preview of the public directory.');
    }


    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Configuration'),
      '#button_type' => 'primary',
    ];
    $form['actions']['quick_scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Quick Scan'),
      '#button_type' => 'secondary',
      '#name' => 'quick_scan',
    ];
    $form['actions']['batch_scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Batch Scan'),
      '#button_type' => 'secondary',
      '#name' => 'batch_scan',
    ];
    $form['scan_help'] = [
      '#markup' => '<div class="description">' . $this->t('If scanning the filesystem takes more than 20 seconds, a batch scan is recommended.') . '</div>',
    ];

    $scan_results = $form_state->get('scan_results');
    if (!$scan_results) {
      $scan_results = $this->state->get('file_adoption.scan_results');
    }

    $progress = $this->state->get('file_adoption.scan_progress');
    if ($progress) {
      $processed = $progress['result']['files'] ?? 0;
      $form['scan_status'] = [
        '#markup' => $this->t('Scan in progress… @count file(s) processed so far.', ['@count' => $processed]),
      ];
    }

    if (!empty($scan_results)) {
      $limit = (int) $config->get('items_per_run');
      $managed_list = array_map([Html::class, 'escape'], $scan_results['to_manage']);

      $form['results_manage'] = [
        '#type' => 'details',
        '#title' => $this->t('Add to Managed Files (@count)', ['@count' => count($managed_list)]),
        '#open' => TRUE,
        '#attributes' => ['id' => 'file-adoption-results'],
      ];
      if (!empty($managed_list)) {
        $display_list = array_slice($managed_list, 0, $limit);
        $markup = '<ul><li>' . implode('</li><li>', $display_list) . '</li></ul>';
        if (!empty($scan_results['orphans']) && $scan_results['orphans'] > count($managed_list)) {
          $remaining = $scan_results['orphans'] - count($managed_list);
          $markup .= '<p>' . $this->formatPlural($remaining, '@count additional file not shown', '@count additional files not shown') . '</p>';
        }
        $form['results_manage']['list'] = [
          '#markup' => Markup::create($markup),
        ];
      }


      $form['actions']['adopt'] = [
        '#type' => 'submit',
        '#value' => $this->t('Adopt'),
        '#button_type' => 'primary',
        '#name' => 'adopt',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $items_per_run = (int) $form_state->getValue('items_per_run');
    if ($items_per_run <= 0) {
      $items_per_run = 100;
    }
    elseif ($items_per_run > 5000) {
      $items_per_run = 5000;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('items_per_run', $items_per_run)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'quick_scan') {
      $time_limit = (int) (getenv('FILE_ADOPTION_SCAN_LIMIT') ?: 20);
      $start = microtime(TRUE);
      @set_time_limit($time_limit);
      $results = $this->fileScanner->scanWithLists($items_per_run);
      $elapsed = microtime(TRUE) - $start;

      if ($elapsed <= $time_limit) {
        $results['dir_counts'] = $this->fileScanner->countFilesByDirectory();
        $form_state->set('scan_results', $results);
        $this->state->set('file_adoption.scan_results', $results);
        $this->state->delete('file_adoption.scan_progress');
        $form_state->setRebuild(TRUE);
        $this->messenger()->addStatus($this->t('Scan complete: @count file(s) found. Counts are limited by "Items per cron run".', ['@count' => $results['files']]));
      }
      else {
        $this->state->delete('file_adoption.scan_results');
        $this->state->set('file_adoption.scan_progress', [
          'resume' => '',
          'result' => ['files' => 0, 'orphans' => 0, 'to_manage' => []],
        ]);
        $batch = [
          'title' => $this->t('Scanning for orphaned files'),
          'operations' => [
            [
              'file_adoption_scan_batch_step',
              [],
              [
                'file' => \Drupal::service('extension.list.module')->getPath('file_adoption') . '/file_adoption.module',
              ],
            ],
          ],
          'finished' => 'file_adoption_scan_batch_finished',
        ];
        batch_set($batch);
        $form_state->setRebuild(TRUE);
        $this->messenger()->addWarning($this->t('Quick scan exceeded the allowed time limit. Falling back to the batch process.'));
      }
    }
    elseif ($trigger === 'batch_scan') {
      $this->state->delete('file_adoption.scan_results');
      $this->state->set('file_adoption.scan_progress', [
        'resume' => '',
        'result' => ['files' => 0, 'orphans' => 0, 'to_manage' => []],
      ]);
      $batch = [
        'title' => $this->t('Scanning for orphaned files'),
        'operations' => [
          [
            'file_adoption_scan_batch_step',
            [],
            [
              'file' => \Drupal::service('extension.list.module')->getPath('file_adoption') . '/file_adoption.module',
            ],
          ],
        ],
        'finished' => 'file_adoption_scan_batch_finished',
      ];
      batch_set($batch);
    }
    elseif ($trigger === 'adopt') {
      $results = $this->state->get('file_adoption.scan_results') ?? $form_state->get('scan_results') ?? [];
      $uris = array_unique($results['to_manage'] ?? []);
      if ($uris) {
        $result = $this->fileScanner->adoptFiles($uris);
        if ($result['count'] > 0) {
          $this->messenger()->addStatus($this->t('@count file(s) adopted.', ['@count' => $result['count']]));
        }
        if (!empty($result['errors'])) {
          foreach ($result['errors'] as $message) {
            $this->messenger()->addError($message);
          }
        }
      }
      else {
        $this->messenger()->addStatus($this->t('No files to adopt.'));
      }
      $form_state->set('scan_results', NULL);
      $this->state->delete('file_adoption.scan_results');
      $form_state->setRebuild(TRUE);
    }
    else {
      $this->messenger()->addStatus($this->t('Configuration saved.'));
    }
  }

}
