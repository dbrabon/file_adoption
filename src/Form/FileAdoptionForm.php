<?php

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\Url;
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


    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Directory Contents Preview'),
      '#open' => TRUE,
      '#attributes' => ['id' => 'file-adoption-preview-wrapper'],
    ];
    $form['preview']['markup'] = [
      '#markup' => Markup::create('<div id="file-adoption-preview">' . $this->t('Scanning files…') . '</div>'),
    ];
    $form['#attached']['library'][] = 'file_adoption/preview';
    $form['#attached']['drupalSettings']['file_adoption']['preview_url'] = Url::fromRoute('file_adoption.preview_ajax')->toString();
    $form['#attached']['drupalSettings']['file_adoption']['preview_title'] = $this->t('Public Directory Contents Preview');


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
      $limit = (int) $config->get('items_per_run');
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
