<?php
declare(strict_types=1);

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\Database\Connection;
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
  protected FileScanner $fileScanner;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
  */
  protected Connection $database;

  /**
   * State service for persisting scan results.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;




  /**
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(FileScanner $fileScanner, Connection $database, StateInterface $state) {
    $this->fileScanner = $fileScanner;
    $this->database = $database;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('database'),
      $container->get('state')
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

    $table_count = (int) $this->database->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();

    $index_count = (int) $this->database->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();

    $form['orphan_table_count'] = [
      '#markup' => $this->t('Orphan table contains @count file(s).', ['@count' => $table_count]),
    ];
    $form['index_table_count'] = [
      '#markup' => $this->t('File index contains @count file(s).', ['@count' => $index_count]),
    ];

    $form['ignore_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ignore Patterns'),
      '#default_value' => $config->get('ignore_patterns'),
      '#description' => $this->t('File paths (relative to public://) to ignore when scanning. Separate multiple patterns with commas or new lines. Files matching these patterns are omitted from the adoption list and directory summaries.'),
    ];

    $patterns = $this->fileScanner->getIgnorePatterns();
    $dir_patterns = [];
    $file_patterns = [];
    foreach ($patterns as $pattern) {
      if (str_ends_with($pattern, '/') || str_ends_with($pattern, '/*')) {
        $dir_patterns[] = rtrim($pattern, '/*');
      }
      else {
        $file_patterns[] = $pattern;
      }
    }

    // Build directory list from the index table.
    $directories = [];
    $result = $this->database->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri', 'ignored'])
      ->execute();
    foreach ($result as $row) {
      $relative = str_starts_with($row->uri, 'public://') ? substr($row->uri, 9) : $row->uri;
      $dir = dirname($relative);
      if ($dir === '.') {
        $dir = '';
      }
      if (!isset($directories[$dir])) {
        $directories[$dir] = ['total' => 0, 'ignored_count' => 0, 'ignored_files' => []];
      }
      $directories[$dir]['total']++;
      if ($row->ignored) {
        $directories[$dir]['ignored_count']++;
        $directories[$dir]['ignored_files'][] = basename($relative);
      }
    }

    foreach ($directories as $dir => &$info) {
      $path = $dir === '' ? '' : $dir . '/';
      $matches = FALSE;
      foreach ($dir_patterns as $pattern) {
        $check = rtrim($pattern, '/') . '/';
        if ($pattern !== '' && fnmatch($check, $path)) {
          $matches = TRUE;
          break;
        }
      }
      $info['ignored'] = $matches || ($info['total'] > 0 && $info['ignored_count'] === $info['total']);
    }
    unset($info);

    if ($directories) {
      ksort($directories);
      $items = [];
      foreach ($directories as $dir => $info) {
        $label = $dir === '' ? 'public://' : $dir . '/';
        $label = Html::escape($label);
        if ($info['ignored']) {
          $label .= ' (ignored)';
        }
        if (!empty($info['ignored_files'])) {
          $files = array_map([Html::class, 'escape'], $info['ignored_files']);
          $label .= ' (' . implode(', ', $files) . ')';
        }
        $items[] = $label;
      }
      $form['directories'] = [
        '#type' => 'details',
        '#title' => $this->t('Directories'),
        '#open' => TRUE,
        'list' => [
          '#markup' => Markup::create('<ul><li>' . implode('</li><li>', $items) . '</li></ul>'),
        ],
      ];
      if ($file_patterns) {
        $pattern_items = array_map([Html::class, 'escape'], $file_patterns);
        $markup = '<p>' . $this->t('Ignored file patterns:') . '</p>';
        $markup .= '<ul><li>' . implode('</li><li>', $pattern_items) . '</li></ul>';
        $form['directories']['patterns'] = [
          '#markup' => Markup::create($markup),
        ];
      }
    }

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

    $options = [
      'every' => $this->t('Every cron run'),
      'hourly' => $this->t('Hourly'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly'),
      'monthly' => $this->t('Monthly'),
      'yearly' => $this->t('Yearly'),
    ];
    $form['cron_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Cron frequency'),
      '#options' => $options,
      '#default_value' => $config->get('cron_frequency') ?: 'yearly',
    ];

    $form['verbose_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verbose logging'),
      '#default_value' => $config->get('verbose_logging'),
      '#description' => $this->t('Write debug information to the log during scanning and adoption.'),
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

    if (!$has_scan_results) {
      $total = $table_count;
      $uris = [];
      if ($total > 0) {
        $query = $this->database->select('file_adoption_orphans', 'fo')
          ->fields('fo', ['uri'])
          ->orderBy('fo.timestamp', 'ASC')
          ->range(0, $limit);
        $uris = $query->execute()->fetchCol();

        $filtered = [];
        foreach ($uris as $uri) {
          $relative = str_starts_with($uri, 'public://') ? substr($uri, 9) : $uri;
          $ignored = FALSE;
          foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $relative)) {
              $ignored = TRUE;
              break;
            }
          }
          $dir = dirname($relative);
          if ($dir === '.') {
            $dir = '';
          }
          if (!$ignored && isset($directories[$dir]) && $directories[$dir]['ignored']) {
            $ignored = TRUE;
          }
          if (!$ignored) {
            $filtered[] = $uri;
          }
        }
        $uris = $filtered;
      }

      if ($total === 0) {
        $scan_results = NULL;
        $last_results = $this->state->get('file_adoption.last_results');
        $last_run = (int) $this->state->get('file_adoption.last_cron', 0);
        if (!$last_run) {
          $this->messenger()->addStatus($this->t('Cron has not yet built the orphan table or is still processing.'));
        }
        elseif (is_array($last_results)) {
          if (!empty($last_results['adopted'])) {
            $this->messenger()->addStatus($this->formatPlural($last_results['adopted'], 'Last cron run adopted @count file.', 'Last cron run adopted @count files.'));
          }
          else {
            $this->messenger()->addStatus($this->t('Last cron run found no orphan files.'));
          }
        }
      }
      else {
        $scan_results = [
          'files' => $total,
          'orphans' => count($uris),
          'to_manage' => $uris,
        ];
      }

      $form_state->set('scan_results', $scan_results);
    }



    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Configuration'),
      '#button_type' => 'primary',
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $items_per_run = (int) $form_state->getValue('items_per_run');
    if ($items_per_run <= 0) {
      $items_per_run = 20;
    }
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('ignore_symlinks', $form_state->getValue('ignore_symlinks'))
      ->set('items_per_run', $items_per_run)
      ->set('cron_frequency', $form_state->getValue('cron_frequency'))
      ->set('verbose_logging', $form_state->getValue('verbose_logging'))
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'adopt') {
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
      $this->messenger()->addStatus($this->t('Run cron to refresh the orphan list. No files will be adopted until Enable Adoption is active.'));
    }
  }

}
