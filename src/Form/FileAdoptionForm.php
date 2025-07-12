<?php
declare(strict_types=1);

namespace Drupal\file_adoption\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\file_adoption\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative report for File Adoption.
 */
class FileAdoptionForm extends FormBase implements ContainerInjectionInterface {

  protected Connection  $db;
  protected FileScanner $scanner;

  /* ------------------------------------------------------------------ */
  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): self {
    $form          = new static();
    $form->db      = $container->get('database');
    $form->scanner = $container->get('file_adoption.scanner');
    return $form;
  }

  /* ------------------------------------------------------------------ */
  public function getFormId(): string {
    return 'file_adoption_admin';
  }

  /* ------------------------------------------------------------------ */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('file_adoption.settings');

    /* ----------------- Settings ------------------------------------- */
    $form['settings'] = [
      '#type'  => 'details',
      '#title' => $this->t('Settings'),
      '#open'  => TRUE,
    ];
    $form['settings']['scan_interval_hours'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Full‑scan interval (hours)'),
      '#default_value' => (int) ($config->get('scan_interval_hours') ?? 24),
      '#min'           => 1,
    ];
    $form['settings']['enable_adoption'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Adoption'),
      '#default_value' => (bool) ($config->get('enable_adoption') ?? FALSE),
    ];
    $form['settings']['items_per_run'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Items per adoption batch'),
      '#default_value' => (int) ($config->get('items_per_run') ?? 20),
      '#min'           => 1,
    ];

    /* ----------------- Ignore patterns ----------------------------- */
    $form['patterns'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Ignore patterns (regex – one per line or comma)'),
      '#default_value' => trim((string) $config->get('ignore_patterns')),
      '#description'   => $this->t('Files whose <em>relative</em> public:// path matches any pattern will be ignored. Wildcards (*, ?) are auto‑converted.'),
    ];

    /* ----------------- Stats --------------------------------------- */
    $total     = $this->db->select('file_adoption_index')->countQuery()->execute()->fetchField();
    $unmanaged = $this->db->select('file_adoption_index')->condition('is_managed', 0)->countQuery()->execute()->fetchField();
    $ignored   = $this->db->select('file_adoption_index')->condition('is_ignored', 1)->countQuery()->execute()->fetchField();

    $form['stats'] = [
      '#markup' => $this->t(
        '<p><strong>@t</strong> indexed – <strong>@u</strong> unmanaged – <strong>@i</strong> ignored.</p>',
        ['@t' => $total, '@u' => $unmanaged, '@i' => $ignored]
      ),
    ];

    /* ----------------- Adoption list ------------------------------ */
    $batch   = (int) ($config->get('items_per_run') ?? 20);
    $orphans = $this->db->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->range(0, $batch)
      ->execute()
      ->fetchCol();

    $form['orphans'] = [
      '#type'  => 'details',
      '#title' => $this->t('Add to Managed Files (@n)', ['@n' => count($orphans)]),
      '#open'  => TRUE,
    ];
    $form['orphans']['list'] = [
      '#theme' => 'item_list',
      '#items' => $orphans ?: [$this->t('No adoptable files found.')],
    ];
    $form['orphans']['adopt'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Adopt now'),
      '#submit' => ['::adoptNow'],
    ];

    /* ----------------- Actions ------------------------------------ */
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    return $form;
  }

  /* ------------------------------------------------------------------ */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $patterns = preg_split('/(\r\n|\n|\r|,)/', (string) $form_state->getValue('patterns', '')) ?: [];
    $converted = [];

    foreach ($patterns as $pattern) {
      $pattern = trim($pattern);
      if ($pattern === '') {
        continue;
      }
      // Auto‑convert simple wildcards to regex.
      if (!preg_match('/[\\\\.^$[\](){}+|]/', $pattern) && strpbrk($pattern, '*?') !== false) {
        $pattern = '^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '#')) . '$';
      }
      // Validate compiled regex.
      if (@preg_match('#' . $pattern . '#', '') === false) {
        $form_state->setErrorByName('patterns', $this->t('Invalid regex: @p', ['@p' => $pattern]));
      }
      $converted[] = $pattern;
    }
    $form_state->setValue('patterns', implode("\n", $converted));
  }

  /* ------------------------------------------------------------------ */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('file_adoption.settings')
      ->set('scan_interval_hours', (int) $form_state->getValue('scan_interval_hours'))
      ->set('enable_adoption',    (bool) $form_state->getValue('enable_adoption'))
      ->set('items_per_run',       (int) $form_state->getValue('items_per_run'))
      ->set('ignore_patterns',     trim((string) $form_state->getValue('patterns')))
      ->save();

    // Recompute ignored flags.
    $patterns = $this->scanner->getIgnorePatterns();
    $table    = 'file_adoption_index';
    $this->db->update($table)->fields(['is_ignored' => 0])->execute();

    if ($patterns) {
      $driver = $this->db->driver();
      $regex  = '(' . implode('|', $patterns) . ')';

      if (in_array($driver, ['mysql', 'mariadb'])) {
        $this->db->update($table)->fields(['is_ignored' => 1])
          ->condition('uri', $regex, 'REGEXP')->execute();
      }
      elseif ($driver === 'pgsql') {
        $this->db->update($table)->fields(['is_ignored' => 1])
          ->condition('uri', $regex, '~')->execute();
      }
      else {
        $result = $this->db->select($table, 'fi')->fields('fi', ['id', 'uri'])->execute();
        foreach ($result as $row) {
          foreach ($patterns as $rx) {
            if (@preg_match('#' . $rx . '#i', $row->uri)) {
              $this->db->update($table)->fields(['is_ignored' => 1])
                ->condition('id', $row->id)->execute();
              break;
            }
          }
        }
      }
    }
    $this->messenger()->addStatus($this->t('Configuration saved. Ignore flags recalculated.'));
  }

  /* ------------------------------------------------------------------ */
  public function adoptNow(array &$form, FormStateInterface $form_state): void {
    $limit = (int) $this->config('file_adoption.settings')->get('items_per_run') ?? 20;
    $this->scanner->adoptUnmanaged($limit);
    $this->messenger()->addStatus($this->t('Adoption run complete.'));
    $form_state->setRebuild(TRUE);
  }

}
