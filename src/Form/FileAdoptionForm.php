<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;

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
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a FileAdoptionForm.
   *
   * @param \Drupal\file_adoption\FileScanner $fileScanner
   *   The file scanner service.
   */
  public function __construct(FileScanner $fileScanner, ModuleHandlerInterface $module_handler) {
    $this->fileScanner = $fileScanner;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('module_handler')
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

    if ($this->moduleHandler->moduleExists('media')) {
      $form['add_to_media'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Add to Media'),
        '#default_value' => $config->get('add_to_media') ?? FALSE,
        '#description' => $this->t('If checked, and the Media module is installed, adopted files will also be added to the Media library.'),
      ];
    }

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Directory Contents Preview'),
      '#open' => TRUE,
    ];

    $public_path = \Drupal::service('file_system')->realpath('public://');
    $preview = [];

    if ($public_path && is_dir($public_path)) {
      $entries = scandir($public_path);
      $patterns = $this->fileScanner->getIgnorePatterns();
      $matched_patterns = [];

      $find_first_file = function ($dir) {
        if (!is_dir($dir)) {
          return NULL;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
          if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
            continue;
          }
          if (is_file($dir . DIRECTORY_SEPARATOR . $item)) {
            return $item;
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

      // Append the count of files that will be scanned.
      $counts = $this->fileScanner->scanAndProcess(FALSE);
      if ($counts['files'] > 0) {
        $root_label .= ' (' . $counts['files'] . ' file';
        $root_label .= $counts['files'] === 1 ? ')' : 's)';
      }

      $preview[] = '<li>' . Html::escape($root_label) . '</li>';

      foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
          continue;
        }

        $absolute = $public_path . DIRECTORY_SEPARATOR . $entry;
        $relative_file = $entry;

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
          if (fnmatch($pattern, $relative_path)) {
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
    if (!empty($scan_results)) {
      $managed_list = array_map([Html::class, 'escape'], $scan_results['to_manage']);
      $media_list = array_map([Html::class, 'escape'], $scan_results['to_media']);

      $form['results_manage'] = [
        '#type' => 'details',
        '#title' => $this->t('Add to Managed Files (@count)', ['@count' => count($managed_list)]),
        '#open' => TRUE,
      ];
      $form['results_manage']['list'] = [
        '#markup' => Markup::create('<ul><li>' . implode('</li><li>', array_slice($managed_list, 0, 500)) . '</li></ul>'),
      ];

      if ($this->moduleHandler->moduleExists('media') && $config->get('add_to_media')) {
        $form['results_media'] = [
          '#type' => 'details',
          '#title' => $this->t('Add to Media (@count)', ['@count' => count($media_list)]),
          '#open' => TRUE,
        ];
        $form['results_media']['list'] = [
          '#markup' => Markup::create('<ul><li>' . implode('</li><li>', array_slice($media_list, 0, 500)) . '</li></ul>'),
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
    $this->config('file_adoption.settings')
      ->set('ignore_patterns', $form_state->getValue('ignore_patterns'))
      ->set('enable_adoption', $form_state->getValue('enable_adoption'))
      ->set('add_to_media', $form_state->getValue('add_to_media') ?? 0)
      ->save();

    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'scan') {
      $result = $this->fileScanner->scanWithLists();
      $form_state->set('scan_results', $result);
      $this->messenger()->addStatus($this->t('Scan complete: @count file(s) found.', ['@count' => $result['files']]));
      $form_state->setRebuild(TRUE);
    }
    elseif ($trigger === 'adopt') {
      $results = $form_state->get('scan_results') ?? [];
      $uris = array_unique(array_merge($results['to_manage'] ?? [], $results['to_media'] ?? []));
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
