<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\File\FileSystemInterface;
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
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   */
  public function __construct(FileScanner $fileScanner, FileSystemInterface $fileSystem) {
    $this->fileScanner = $fileScanner;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_system')
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

    $form['ignore_symlinks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ignore symlinks'),
      '#default_value' => $config->get('ignore_symlinks'),
      '#description' => $this->t('Skip symbolic links when scanning for orphaned files.'),
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

    $has_scan_results = $form_state->has('scan_results');
    $scan_results = $form_state->get('scan_results');
    $limit = (int) $config->get('items_per_run');

    // If the form does not already have scan results, attempt to load any
    // records saved during cron runs. No scan is performed automatically.
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    $from_batch = $this->getRequest()->query->get('batch_complete');

    if (!$has_scan_results) {
      $database = \Drupal::database();
      $total = (int) $database->select('file_adoption_orphans')->countQuery()->execute()->fetchField();
      $uris = [];
      if ($total > 0) {
        $uris = $database->select('file_adoption_orphans', 'fo')
          ->fields('fo', ['uri'])
          ->orderBy('timestamp', 'ASC')
          ->range(0, $limit)
          ->execute()
          ->fetchCol();
      }

      $scan_results = [
        'files' => $total,
        'orphans' => $total,
        'to_manage' => $uris,
      ];

      // Only display the "No scan results" message when the page loads without a
      // recent scan being triggered.
      if ($total === 0 && !$from_batch && $trigger !== 'scan') {
        $scan_results = NULL;
        $this->messenger()->addStatus($this->t('No scan results found. Click "Scan Now" or wait for cron.'));
      }
    }

    // Prevent preview logic from executing until a scan has been explicitly
    // triggered or a batch scan just completed.
    if ($trigger === 'scan' || $from_batch) {


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
        $patterns = $this->fileScanner->getIgnorePatterns();
        $ignore_symlinks = $config->get('ignore_symlinks');

        $scan_tree = $this->fileScanner->listUnmanagedRecursive('');
        $dir_list = array_merge([''], $scan_tree['directories']);

        $matched_patterns = [];
        $iterator = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($public_path, \FilesystemIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
          if ($ignore_symlinks && $info->isLink()) {
            continue;
          }
          $relative = str_replace('\\', '/', $iterator->getSubPathname());
          if ($relative === '' || preg_match('/(^|\/)(\.|\.{2})/', $relative)) {
            continue;
          }
          foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $relative)) {
              $matched_patterns[$pattern] = TRUE;
            }
          }
        }

        $highlight_dirs = [];
        if (!empty($scan_results['to_manage'])) {
          $highlight_dirs = $this->fileScanner->filterDirectoriesWithUnmanaged($dir_list, $scan_results['to_manage']);
        }
        $highlight_map = array_flip($highlight_dirs);

        $children = [];
        foreach ($scan_tree['directories'] as $dir) {
          $parent = dirname(rtrim($dir, '/'));
          if ($parent === '.') {
            $parent = '';
          }
          $children[$parent][] = rtrim($dir, '/');
        }

        $scanner = $this->fileScanner;
        $render_dir = function ($dir) use (&$render_dir, $children, $highlight_map, $scanner) {
          $label = $dir === '' ? 'public://' : basename($dir) . '/';
          $count = $scanner->countFiles($dir);
          if ($count > 0) {
            $label .= ' (' . $count . ')';
          }
          $label = Html::escape($label);
          $key = $dir === '' ? '' : $dir . '/';
          if (isset($highlight_map[$key])) {
            $label = '<strong>' . $label . '</strong>';
          }
          $output = '<li>' . $label;
          if (!empty($children[$dir])) {
            $items = '';
            sort($children[$dir]);
            foreach ($children[$dir] as $child) {
              $items .= $render_dir($child);
            }
            $output .= '<ul>' . $items . '</ul>';
          }
          $output .= '</li>';
          return $output;
        };

        $preview[] = $render_dir('');

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
        } else {
          $form['preview']['markup'] = [
            '#markup' => Markup::create('<div>' . $list_html . '</div>'),
          ];
        }
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
    $form['actions']['batch_scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Batch Scan'),
      '#button_type' => 'secondary',
      '#name' => 'batch_scan',
    ];


    if ($scan_results !== NULL) {
      $managed_list = array_map([Html::class, 'escape'], $scan_results['to_manage']);

      $form['results_manage'] = [
        '#type' => 'details',
        '#title' => $this->t('Add to Managed Files (@count)', ['@count' => count($managed_list)]),
        '#open' => TRUE,
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
      $items_per_run = 20;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('ignore_symlinks', $form_state->getValue('ignore_symlinks'))
      ->set('items_per_run', $items_per_run)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'scan') {
      $limit = (int) $this->config('file_adoption.settings')->get('items_per_run');
      $result = $this->fileScanner->scanWithLists($limit);
      $form_state->set('scan_results', $result);
      $this->messenger()->addStatus($this->t('Scan complete: @count file(s) found.', ['@count' => $result['files']]));
      $form_state->setRebuild(TRUE);
    }
    elseif ($trigger === 'batch_scan') {
      $operations = [
        [[static::class, 'batchScanStep'], []],
      ];
      $batch = [
        'title' => $this->t('Batch scanning files'),
        'operations' => $operations,
        'finished' => [static::class, 'batchScanFinished'],
      ];
      batch_set($batch);
      // Redirect back to the configuration page with a query flag so the
      // results preview is displayed once the batch completes.
      $form_state->setRedirect('file_adoption.config_form', [], ['query' => ['batch_complete' => 1]]);
    }
    elseif ($trigger === 'adopt') {
      $results = $form_state->get('scan_results') ?? [];
      $uris = array_unique($results['to_manage'] ?? []);
      if ($uris) {
        $count = $this->fileScanner->adoptFiles($uris);
        $this->messenger()->addStatus($this->t('@count file(s) adopted.', ['@count' => $count]));
      }
      else {
        $this->messenger()->addStatus($this->t('No files to adopt.'));
      }
      $form_state->set('scan_results', NULL);
      $form_state->setRebuild(TRUE);
    }
    else {
      $this->messenger()->addStatus($this->t('Configuration saved.'));
    }
  }

  /**
   * Batch operation callback for scanning.
  */
  public static function batchScanStep(&$context) {
    $scanner = \Drupal::service('file_adoption.file_scanner');
    $scanner->recordOrphansBatch($context);
  }

  /**
   * Batch finished callback.
  */
  public static function batchScanFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Batch scan complete: @files file(s) scanned, @orphans orphan(s) found.', [
        '@files' => $results['files'] ?? 0,
        '@orphans' => $results['orphans'] ?? 0,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('Batch scan encountered an error.'));
    }
  }

}
