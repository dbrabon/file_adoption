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
use Drupal\Component\Serialization\Json;

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

    $folder_depth = $config->get('folder_depth');
    if ($folder_depth === NULL || $folder_depth < 0) {
      $folder_depth = 2;
    }
    $form['folder_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Folder depth'),
      '#default_value' => $folder_depth,
      '#min' => 0,
      '#description' => $this->t('Maximum subdirectory depth to scan. Use 0 for unlimited depth.'),
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
      $form['#attached']['drupalSettings']['file_adoption']['dirs_url'] = Url::fromRoute('file_adoption.dirs_ajax')->toString();
      $form['#attached']['drupalSettings']['file_adoption']['total_url'] = Url::fromRoute('file_adoption.pending_count')->toString();
      $form['#attached']['drupalSettings']['file_adoption']['preview_title'] = $this->t('Public Directory Contents Preview');
      $form['#attached']['drupalSettings']['file_adoption']['ignore_patterns'] =
        $this->fileScanner->getIgnorePatterns();
    }
    else {
      $form['preview']['#description'] = $this->t('Start a scan to build a preview in the background. The list updates automatically as scanning progresses.');
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
    $form['actions']['clear_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Cache'),
      '#button_type' => 'secondary',
      '#name' => 'clear_cache',
    ];
    $form['scan_help'] = [
      '#markup' => '<div class="description">' . $this->t('Quick scans run for up to 20 seconds before continuing asynchronously via a batch process.') . '</div>',
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
      $shown = min($limit, count($managed_list));

      $form['results_manage'] = [
        '#type' => 'details',
        '#title' => $this->t('Add to Managed Files'),
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
        // Store the URIs shown in the list so they can be adopted reliably.
        $display_uris = array_slice($scan_results['to_manage'], 0, $limit);
        $form['results_manage']['shown_uris'] = [
          '#type' => 'hidden',
          '#value' => Json::encode($display_uris),
        ];
      }

      $form['results_total'] = [
        '#markup' => '<div id="file-adoption-total-count"></div>',
      ];


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
    $folder_depth = (int) $form_state->getValue('folder_depth');
    if ($folder_depth < 0) {
      $folder_depth = 0;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('items_per_run', $items_per_run)
      ->set('folder_depth', $folder_depth)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'quick_scan') {
      $time_limit = (int) (getenv('FILE_ADOPTION_SCAN_LIMIT') ?: 20);
      @set_time_limit($time_limit);
      $chunk = $this->fileScanner->scanChunk('', $items_per_run, $time_limit);

      if ($chunk['resume'] === '') {
        $chunk['dir_counts'] = $this->fileScanner->countFilesByDirectory();
        $form_state->set('scan_results', $chunk);
        $this->state->set('file_adoption.scan_results', $chunk);
        $this->state->delete('file_adoption.scan_progress');
        $form_state->setRebuild(TRUE);
        $this->messenger()->addStatus($this->t('Scan complete: @count file(s) found. Counts are limited by "Items per cron run".', ['@count' => $chunk['files']]));
      }
      else {
        $this->state->delete('file_adoption.scan_results');
        $this->state->set('file_adoption.scan_progress', [
          'resume' => $chunk['resume'],
          'result' => [
            'files' => $chunk['files'],
            'orphans' => $chunk['orphans'],
            'to_manage' => $chunk['to_manage'],
            'dir_counts' => $chunk['dir_counts'],
          ],
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
        'result' => [
          'files' => 0,
          'orphans' => 0,
          'to_manage' => [],
          'dir_counts' => [],
        ],
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
      $chunk = [];

      // Prefer the URIs that were shown to the user, if available.
      $shown_json = $form_state->getValue(['results_manage', 'shown_uris']);
      if ($shown_json !== NULL) {
        $shown_list = Json::decode($shown_json);
        if (is_array($shown_list)) {
          $chunk = $shown_list;
        }
      }

      if (!$chunk) {
        $uris = array_unique($results['to_manage'] ?? []);

        $limit = (int) $this->config('file_adoption.settings')->get('items_per_run');
        if ($limit <= 0) {
          $limit = 100;
        }
        elseif ($limit > 5000) {
          $limit = 5000;
        }

        $chunk = array_slice($uris, 0, $limit);
      }

      if ($chunk) {
        $result = $this->fileScanner->adoptFiles($chunk);
        if ($result['count'] > 0) {
          $this->messenger()->addStatus($this->t('@count file(s) adopted.', ['@count' => $result['count']]));
        }
        if (!empty($result['errors'])) {
          foreach ($result['errors'] as $message) {
            $this->messenger()->addError($message);
          }
        }

        foreach ($chunk as $uri) {
          $relative = str_replace('public://', '', $uri);
          $dir = dirname($relative);
          if ($dir === '.') {
            $dir = '';
          }
          while (TRUE) {
            if (isset($results['dir_counts'][$dir]) && $results['dir_counts'][$dir] > 0) {
              $results['dir_counts'][$dir]--;
              if ($results['dir_counts'][$dir] === 0) {
                unset($results['dir_counts'][$dir]);
              }
            }
            if ($dir === '') {
              break;
            }
            $dir = dirname($dir);
            if ($dir === '.') {
              $dir = '';
            }
          }
        }

        $results['orphans'] -= $result['count'];
        $results['to_manage'] = array_values(array_diff($results['to_manage'], $chunk));
      }
      else {
        $this->messenger()->addStatus($this->t('No files to adopt.'));
      }

      if (empty($results['to_manage'])) {
        $form_state->set('scan_results', NULL);
        $this->state->delete('file_adoption.scan_results');
      }
      else {
        $this->state->set('file_adoption.scan_results', $results);
        $form_state->set('scan_results', $results);
      }

      $form_state->setRebuild(TRUE);
    }
    elseif ($trigger === 'clear_cache') {
      $this->state->delete('file_adoption.managed_cache');
      $this->state->delete('file_adoption.dir_inventory');
      $this->state->delete('file_adoption.examples_cache');
      $this->state->delete('file_adoption.scan_results');
      $this->state->delete('file_adoption.scan_progress');
      $form_state->set('scan_results', NULL);
      $this->messenger()->addStatus($this->t('Caches cleared.'));
    }
    else {
      $results = $this->state->get('file_adoption.scan_results');
      if ($results && !empty($results['to_manage'])) {
        $original = $results['to_manage'];
        $filtered = $this->fileScanner->filterUris($original);
        if ($filtered !== $original) {
          $removed = array_diff($original, $filtered);
          foreach ($removed as $uri) {
            $relative = str_replace('public://', '', $uri);
            $dir = dirname($relative);
            if ($dir === '.') {
              $dir = '';
            }
            while (TRUE) {
              if (isset($results['dir_counts'][$dir]) && $results['dir_counts'][$dir] > 0) {
                $results['dir_counts'][$dir]--;
                if ($results['dir_counts'][$dir] === 0) {
                  unset($results['dir_counts'][$dir]);
                }
              }
              if ($dir === '') {
                break;
              }
              $dir = dirname($dir);
              if ($dir === '.') {
                $dir = '';
              }
            }
          }
          $results['to_manage'] = array_values($filtered);
        }
        $this->state->set('file_adoption.scan_results', $results);
        $form_state->set('scan_results', $results);
        $form_state->setRebuild(TRUE);
      }

      $this->messenger()->addStatus($this->t('Configuration saved.'));
    }
  }

}
