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

    // Prevent preview and orphan listing logic from executing until a scan has
    // been explicitly triggered.
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'scan') {



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
      $ignore_symlinks = $config->get('ignore_symlinks');

        $find_first_file = function ($dir) use ($ignore_symlinks) {
          if (!is_dir($dir)) {
            return NULL;
          }
          $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
          foreach ($it as $file) {
            if ($ignore_symlinks && $file->isLink()) {
              continue;
            }
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
        if ($ignore_symlinks && is_link($absolute)) {
          continue;
        }
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
        if ($ignore_symlinks && is_link($absolute)) {
          continue;
        }

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

    $scan_results = $form_state->get('scan_results');
    $limit = (int) $config->get('items_per_run');

    // If the form does not already have scan results, attempt to load any
    // records saved during cron runs. No scan is performed automatically.
    if (empty($scan_results)) {
      $database = \Drupal::database();
      $total = (int) $database->select('file_adoption_orphans')->countQuery()->execute()->fetchField();
      if ($total > 0) {
        $uris = $database->select('file_adoption_orphans', 'fo')
          ->fields('fo', ['uri'])
          ->orderBy('timestamp', 'ASC')
          ->range(0, $limit)
          ->execute()
          ->fetchCol();
        $scan_results = [
          'files' => $total,
          'orphans' => $total,
          'to_manage' => $uris,
        ];
      }
      else {
        // Ensure the form displays no orphan list when there are no saved
        // results. A manual scan must be triggered explicitly.
        $scan_results = [];
        $this->messenger()->addStatus($this->t('No scan results found. Click "Scan Now" or wait for cron.'));
      }
    }

    if (!empty($scan_results)) {
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

}
