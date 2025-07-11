<?php
declare(strict_types=1);

namespace Drupal\file_adoption\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_adoption\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative report for File Adoption.
 */
class FileAdoptionForm extends FormBase {

  protected Connection  $db;
  protected FileScanner $scanner;

  public static function create(ContainerInterface $c): self {
    $form          = new static();
    $form->db      = $c->get('database');
    $form->scanner = $c->get('file_adoption.scanner');
    return $form;
  }

  public function getFormId(): string {
    return 'file_adoption_admin';
  }

  public function buildForm(array $form, FormStateInterface $state): array {
    $config = $this->config('file_adoption.settings');

    /* --------------------------------------------------- Settings section */
    $form['settings'] = [
      '#type'  => 'details',
      '#title' => $this->t('Settings'),
      '#open'  => TRUE,
    ];
    $form['settings']['scan_interval_hours'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Full‑scan interval (hours)'),
      '#default_value' => (int) ($config->get('scan_interval_hours') ?? 24),
      '#description'   => $this->t('A full recursive scan of public:// will run at most once per this many hours.'),
      '#min'           => 1,
    ];
    $form['settings']['items_per_run'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Items per adoption batch'),
      '#default_value' => (int) ($config->get('items_per_run') ?? 20),
      '#min'           => 1,
    ];

    /* --------------------------------------------------- Ignore patterns */
    $form['patterns'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Ignore patterns (regex – one per line or comma)'),
      '#default_value' => trim((string) $config->get('ignore_patterns')),
      '#description'   => $this->t('Files whose <em>relative</em> public:// path matches any pattern will be ignored. Wildcards (*, ?) will be automatically converted.'),
    ];

    /* --------------------------------------------------- Directories & stats */
    $totalRows = $this->db->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();
    $unmanaged = $this->db->select('file_adoption_index')
      ->condition('is_managed', 0)->countQuery()->execute()->fetchField();
    $ignored   = $this->db->select('file_adoption_index')
      ->condition('is_ignored', 1)->countQuery()->execute()->fetchField();

    $form['stats'] = [
      '#markup' => $this->t(
        '<p><strong>@t</strong> indexed – <strong>@u</strong> unmanaged – <strong>@i</strong> ignored.</p>',
        ['@t' => $totalRows, '@u' => $unmanaged, '@i' => $ignored],
      ),
    ];

    /* --------------------------------------------------- Adoption list */
    $batch = (int) ($config->get('items_per_run') ?? 20);
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

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save configuration'),
    ];
    return $form;
  }

  /* ------------------------------ validation – convert legacy wildcards */
  public function validateForm(array &$form, FormStateInterface $state): void {
    $patterns = preg_split('/(\r\n|\n|\r|,)/', (string) $state->getValue('patterns', '')) ?: [];
    $converted = [];

    foreach ($patterns as $pattern) {
      $pattern = trim($pattern);
      if ($pattern === '') {
        continue;
      }

      // Detect simple wildcard strings, convert to regex automatically.
      if (!preg_match('/[\\\\.^$[\](){}+|]/', $pattern) && strpbrk($pattern, '*?') !== false) {
        $regex = '^' . str_replace(['\*', '\?'], ['.*', '.'],
          preg_quote($pattern, '#')) . '$';
        $this->messenger()->addWarning($this->t(
          'Wildcard pattern “@old” converted to regex “@new”.',
          ['@old' => $pattern, '@new' => $regex]
        ));
        $pattern = $regex;
      }

      // Validate the regex.
      if (@preg_match('#' . $pattern . '#', '') === false) {
        $state->setErrorByName('patterns',
          $this->t('Invalid regex: @p', ['@p' => $pattern]));
      }
      $converted[] = $pattern;
    }

    // Replace textarea value with converted patterns for submit.
    $state->setValue('patterns', implode("\n", $converted));
  }

  /* ------------------------------ submit */
  public function submitForm(array &$form, FormStateInterface $state): void {
    $config = $this->configFactory()->getEditable('file_adoption.settings');
    $config
      ->set('scan_interval_hours', (int) $state->getValue('scan_interval_hours'))
      ->set('items_per_run',       (int) $state->getValue('items_per_run'))
      ->set('ignore_patterns',     trim((string) $state->getValue('patterns')))
      ->save();

    // Recompute is_ignored for all rows quickly & portably.
    $patterns = $this->scanner->getIgnorePatterns();
    $table    = 'file_adoption_index';

    // Reset all rows to not ignored.
    $this->db->update($table)->fields(['is_ignored' => 0])->execute();

    if ($patterns) {
      $driver = $this->db->driver();
      $regex  = '(' . implode('|', array_map(fn($p) => $p, $patterns)) . ')';

      if (in_array($driver, ['mysql', 'mariadb'])) {
        // Fast SQL update using REGEXP
        $this->db->update($table)
          ->fields(['is_ignored' => 1])
          ->condition('uri', $regex, 'REGEXP')
          ->execute();
      }
      elseif ($driver === 'pgsql') {
        $this->db->update($table)
          ->fields(['is_ignored' => 1])
          ->condition('uri', $regex, '~')
          ->execute();
      }
      else {
        // Portable fallback: iterate in PHP (chunked).
        $result = $this->db->select($table, 'fi')
          ->fields('fi', ['id', 'uri'])
          ->execute();

        foreach ($result as $row) {
          foreach ($patterns as $rx) {
            if (@preg_match('#' . $rx . '#i', $row->uri)) {
              $this->db->update($table)
                ->fields(['is_ignored' => 1])
                ->condition('id', $row->id)
                ->execute();
              break;
            }
          }
        }
      }
    }

    $this->messenger()->addStatus($this->t('Configuration saved and ignore flags recalculated.'));
  }

  /* ------------------------------ adopt button handler */
  public function adoptNow(array &$form, FormStateInterface $state): void {
    $limit = (int) $this->config('file_adoption.settings')->get('items_per_run') ?? 20;
    $this->scanner->adoptUnmanaged($limit);
    $this->messenger()->addStatus($this->t('Adoption run complete.'));
    $state->setRebuild(true);
  }
}
