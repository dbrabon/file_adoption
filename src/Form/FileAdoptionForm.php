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

    $options = [
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
      '#default_value' => $config->get('cron_frequency') ?: 'daily',
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

      if ($total === 0) {
        $scan_results = NULL;
        $this->messenger()->addStatus($this->t('Cron has not yet built the orphan table or is still processing.'));
      }
      else {
        $scan_results = [
          'files' => $total,
          'orphans' => $total,
          'to_manage' => $uris,
        ];
      }

      $form_state->set('scan_results', $scan_results);
    }

    if ($scan_results !== NULL) {
      $public_path = $this->fileSystem->realpath('public://');
      $directories = [];
      $symlinks = [];
      foreach ($scan_results['to_manage'] as $uri) {
        $relative = str_starts_with($uri, 'public://') ? substr($uri, 9) : $uri;
        $dir = dirname($relative);
        if ($dir === '.') {
          $dir = '';
        }
        $directories[$dir] = TRUE;
        if ($public_path && is_link($public_path . '/' . $relative)) {
          $symlinks[] = $relative;
        }
      }

      if ($directories) {
        $items = [];
        foreach (array_keys($directories) as $dir) {
          $items[] = Html::escape($dir === '' ? 'public://' : $dir . '/');
        }
        $form['preview'] = [
          '#type' => 'details',
          '#title' => $this->t('Orphan directories'),
          '#open' => TRUE,
          'list' => [
            '#markup' => Markup::create('<ul><li>' . implode('</li><li>', $items) . '</li></ul>'),
          ],
        ];
      }

      if ($symlinks) {
        $form['preview']['symlinks'] = [
          '#type' => 'details',
          '#title' => $this->t('Symlinks'),
          '#open' => TRUE,
          'list' => [
            '#markup' => Markup::create('<ul><li>' . implode('</li><li>', array_map([Html::class, 'escape'], $symlinks)) . '</li></ul>'),
          ],
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

    // Close the outer scan results check.
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
      ->set('cron_frequency', $form_state->getValue('cron_frequency'))
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
    }
  }

}
