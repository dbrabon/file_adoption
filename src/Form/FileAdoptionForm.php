<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\File\FileSystemInterface;
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
  public function __construct(FileScanner $fileScanner, FileSystemInterface $fileSystem, PrivateTempStoreFactory $tempStoreFactory) {
    $this->fileScanner = $fileScanner;
    $this->fileSystem = $fileSystem;
    $this->tempStore = $tempStoreFactory->get('file_adoption');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_adoption.file_scanner'),
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

    // Load any batch scan results stored in the temp store.
    if (!$form_state->get('scan_results')) {
      if ($data = $this->tempStore->get('scan_results')) {
        $form_state->set('scan_results', $data);
        $this->tempStore->delete('scan_results');
      }
      else {
        // Fall back to cached inventory if available and valid.
        $cache = \Drupal::cache()->get('file_adoption.inventory');
        $lifetime = (int) $config->get('cache_lifetime');
        if ($lifetime <= 0) {
          $lifetime = 86400;
        }
        if ($cache && isset($cache->data['timestamp']) && (time() - $cache->data['timestamp'] < $lifetime)) {
          $form_state->set('scan_results', $cache->data['results']);
        }
      }
    }

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

    $lifetime = (int) $config->get('cache_lifetime');
    if ($lifetime <= 0) {
      $lifetime = 86400;
    }
    $form['cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Inventory cache lifetime (seconds)'),
      '#default_value' => $lifetime,
      '#min' => 1,
    ];



    $public_path = $this->fileSystem->realpath('public://');
    $file_count = 0;
    if ($public_path) {
      $file_count = $this->fileScanner->countFiles();
    }

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Directory Contents Preview (@count)', [
        '@count' => $file_count,
      ]),
      '#open' => TRUE,
    ];
    $preview = [];

    if ($public_path && is_dir($public_path)) {
      $entries = scandir($public_path);
      $patterns = $this->fileScanner->getIgnorePatterns();
      $matched_patterns = [];

        $find_first_file = function ($dir) {
          if (!is_dir($dir)) {
            return NULL;
          }
          $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
          foreach ($it as $file) {
            if ($file->isFile()) {
              $name = $file->getFilename();
              if (!str_starts_with($name, '.')) {
                return $name;
              }
            }
          }
          return NULL;
        };

      // Show the root public:// folder with a sample file if available.
      $root_first = $find_first_file($public_path);
      $root_label = 'public://';
      if ($root_first) {
        $root_label .= ' (e.g., ' . $root_first . ')';
      }

      // Count only files directly within the root public directory that are not
      // ignored by configured patterns.
      $root_count = 0;
      foreach ($entries as $entry_check) {
        if ($entry_check === '.' || $entry_check === '..' || str_starts_with($entry_check, '.')) {
          continue;
        }
        $absolute = $public_path . DIRECTORY_SEPARATOR . $entry_check;
        if (is_file($absolute)) {
          $ignored = FALSE;
          foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $entry_check)) {
              $ignored = TRUE;
              $matched_patterns[$pattern] = TRUE;
              break;
            }
          }
          if (!$ignored) {
            $root_count++;
          }
        }
      }
      if ($root_count > 0) {
        $root_label .= ' (' . $root_count . ')';
      }

      $preview[] = '<li>' . Html::escape($root_label) . '</li>';

      foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
          continue;
        }

        $absolute = $public_path . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($absolute)) {
          $relative_path = $entry . '/*';
          $first_file = $find_first_file($absolute);
          $label = $entry . '/';
          if ($first_file) {
            $label .= ' (e.g., ' . $first_file . ')';
          }
        }
        else {
          // Only list files that match an ignore pattern.
          $relative_path = $entry;
          $label = $entry;
        }

        $matched = '';
        foreach ($patterns as $pattern) {
          if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $entry)) {
            $matched = $pattern;
            $matched_patterns[$pattern] = TRUE;
            break;
          }
        }

        if (is_dir($absolute)) {
          if ($matched) {
            $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          }
          else {
            $count_dir = $this->fileScanner->countFiles($entry);
            if ($count_dir > 0) {
              $label .= ' (' . $count_dir . ')';
            }
            $preview[] = '<li>' . Html::escape($label) . '</li>';
          }
        }
        elseif ($matched) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
        }
      }

      // Display patterns that did not match any current file or directory.
      foreach ($patterns as $pattern) {
        if (!isset($matched_patterns[$pattern])) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($pattern) . ' (pattern not found)</span></li>';
        }
      }
    }

    if (!empty($preview)) {
      $list_html = '<ul>' . implode('', $preview) . '</ul>';
      if (count($preview) > 20) {
        $form['preview']['list'] = [
          '#markup' => Markup::create('<div>' . $list_html . '</div>'),
        ];
      }
      else {
        $form['preview']['markup'] = [
          '#markup' => Markup::create('<div>' . $list_html . '</div>'),
        ];
      }
    }

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
      '#value' => $this->t('Scan Now'),
      '#button_type' => 'secondary',
      '#name' => 'scan',
    ];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh inventory'),
      '#button_type' => 'secondary',
      '#name' => 'refresh',
    ];

    $scan_results = $form_state->get('scan_results');
    if (!empty($scan_results)) {
      $items_per_run = (int) $config->get('items_per_run');
      $managed_list = array_map([Html::class, 'escape'], $scan_results['to_manage']);
      $display_count = min($items_per_run, count($managed_list));

      $form['results_manage'] = [
        '#type' => 'details',
        '#title' => $this->t(
          'Add to Managed Files (@display of @total)',
          ['@display' => $display_count, '@total' => count($managed_list)]
        ),
        '#open' => TRUE,
      ];
      if (!empty($managed_list)) {
        $display_list = array_slice($managed_list, 0, $items_per_run);
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $items_per_run = (int) $form_state->getValue('items_per_run');
    if ($items_per_run <= 0) {
      $items_per_run = 20;
    }
    $cache_lifetime = (int) $form_state->getValue('cache_lifetime');
    if ($cache_lifetime <= 0) {
      $cache_lifetime = 86400;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('follow_symlinks', $form_state->getValue('follow_symlinks'))
      ->set('items_per_run', $items_per_run)
      ->set('cache_lifetime', $cache_lifetime)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'scan' || $trigger === 'refresh') {
      if ($trigger === 'refresh') {
        \Drupal::cache()->delete('file_adoption.inventory');
      }
      else {
        $cache = \Drupal::cache()->get('file_adoption.inventory');
        $lifetime = $cache_lifetime;
        if ($cache && isset($cache->data['timestamp']) && (time() - $cache->data['timestamp'] < $lifetime)) {
          $form_state->set('scan_results', $cache->data['results']);
          $form_state->setRebuild(TRUE);
          $this->messenger()->addStatus($this->t('Loaded cached inventory.'));
          return;
        }
      }

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
      $results = $form_state->get('scan_results') ?? [];
      $limit = max(1, (int) $this->config('file_adoption.settings')->get('items_per_run'));
      $uris = array_unique($results['to_manage'] ?? []);
      $to_adopt = array_slice($uris, 0, $limit);
      $count = 0;
      foreach ($to_adopt as $uri) {
        if ($this->fileScanner->adoptFile($uri)) {
          $count++;
          $index = array_search($uri, $uris, TRUE);
          if ($index !== FALSE) {
            unset($uris[$index]);
          }
          if (!empty($results['orphans']) && $results['orphans'] > 0) {
            $results['orphans']--;
          }
        }
      }

      $results['to_manage'] = array_values($uris);
      $form_state->set('scan_results', $results);

      $lifetime = (int) $this->config('file_adoption.settings')->get('cache_lifetime');
      if ($lifetime <= 0) {
        $lifetime = 86400;
      }
      $cache_data = [
        'results' => $results,
        'timestamp' => time(),
      ];
      \Drupal::cache()->set('file_adoption.inventory', $cache_data, time() + $lifetime);

      if ($count) {
        $this->messenger()->addStatus($this->t('@count file(s) adopted.', ['@count' => $count]));
      }
      else {
        $this->messenger()->addStatus($this->t('No files to adopt.'));
      }
      $form_state->setRebuild(TRUE);
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
      $context['sandbox']['offset'] = 0;
      $context['sandbox']['total'] = $scanner->countFiles();
      $context['results'] = [
        'files' => 0,
        'orphans' => 0,
        'to_manage' => [],
      ];
    }

    $chunk = $scanner->scanChunk($context['sandbox']['offset'], $limit);
    $context['sandbox']['offset'] = $chunk['offset'];
    $context['results']['files'] += $chunk['results']['files'];
    $context['results']['orphans'] += $chunk['results']['orphans'];
    $context['results']['to_manage'] = array_merge($context['results']['to_manage'], $chunk['results']['to_manage']);

    if ($context['sandbox']['offset'] >= $context['sandbox']['total']) {
      $context['finished'] = 1;
    }
    elseif ($context['sandbox']['total'] > 0) {
      $context['finished'] = $context['sandbox']['offset'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations) {
    $store = \Drupal::service('tempstore.private')->get('file_adoption');
    if ($success) {
      $store->set('scan_results', $results);
      \Drupal::messenger()->addStatus(\Drupal::translation()->translate('Scan complete: @count file(s) found.', ['@count' => $results['files']]));
      $lifetime = (int) \Drupal::config('file_adoption.settings')->get('cache_lifetime');
      if ($lifetime <= 0) {
        $lifetime = 86400;
      }
      $cache_data = [
        'results' => $results,
        'timestamp' => time(),
      ];
      \Drupal::cache()->set('file_adoption.inventory', $cache_data, time() + $lifetime);
    }
    else {
      \Drupal::messenger()->addError(\Drupal::translation()->translate('Scan failed.'));
    }
  }

}
