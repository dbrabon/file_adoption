<?php
declare(strict_types=1);

namespace Drupal\file_adoption\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\file_adoption\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative report for File Adoption.
 */
class FileAdoptionForm extends FormBase {

  protected Connection  $db;
  protected FileScanner $scanner;

  public static function create(ContainerInterface $container): self {
    /** @var self $form */
    $form = new static();
    $form->db      = $container->get('database');
    $form->scanner = $container->get('file_adoption.scanner');
    return $form;
  }

  public function getFormId(): string {
    return 'file_adoption_admin';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config        = $this->config('file_adoption.settings');
    $items_per_run = (int) $config->get('items_per_run') ?? 20;

    // Statistics.
    $stats = $this->db->select('file_adoption_index', 'fi')
      ->fields('fi', [
        'is_ignored',
        'is_managed',
      ])
      ->addExpression('COUNT(*)', 'cnt')
      ->groupBy('is_ignored')
      ->groupBy('is_managed')
      ->execute()
      ->fetchAllKeyed(0, 1);

    $total   = array_sum($stats);
    $managed = $stats['0']['1'] ?? 0;
    $ignored = $stats['1']['0'] ?? 0;

    $form['summary'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Status'),
      '#open'   => TRUE,
      'markup'  => [
        '#markup' => $this->t(
          '@total files indexed – @managed managed – @ignored ignored.',
          ['@total' => $total, '@managed' => $managed, '@ignored' => $ignored]
        ),
      ],
    ];

    /* ---------------------------------------------------------------------
     * Directories
     * ------------------------------------------------------------------- */
    $depth_limit = (int) $config->get('directory_depth') ?? 2;
    $dirs_query  = $this->db->select('file_adoption_index', 'fi')
      ->fields('fi', ['directory_depth'])
      ->condition('directory_depth', $depth_limit, '<=')
      ->addExpression('COUNT(*)', 'cnt')
      ->groupBy('directory_depth')
      ->orderBy('directory_depth')
      ->execute()
      ->fetchAll();

    $dir_items = [];
    foreach ($dirs_query as $row) {
      $dir_items[] = $this->t(
        'Depth @d – @c files',
        ['@d' => $row->directory_depth, '@c' => $row->cnt]
      );
    }

    $form['directories'] = [
      '#type'  => 'details',
      '#title' => $this->t('Directories'),
      '#open'  => FALSE,
      'items'  => [
        '#theme' => 'item_list',
        '#items' => $dir_items,
      ],
    ];

    /* ---------------------------------------------------------------------
     * Add to Managed Files (UI list)
     * ------------------------------------------------------------------- */
    $orphans = $this->db->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->range(0, $items_per_run)
      ->execute()
      ->fetchCol();

    $form['add'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Add to Managed Files (@n)', ['@n' => count($orphans)]),
      '#open'   => TRUE,
    ];

    $form['add']['list'] = [
      '#theme' => 'item_list',
      '#items' => $orphans ?: [$this->t('No adoptable files found.')],
    ];

    $form['add']['adopt'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Adopt Now'),
      '#submit' => ['::adoptNow'],
    ];

    /* ---------------------------------------------------------------------
     * Ignore patterns textarea
     * ------------------------------------------------------------------- */
    $form['patterns'] = [
      '#type'  => 'textarea',
      '#title' => $this->t('Ignore Patterns (regex – one per line or comma‑separated)'),
      '#default_value' => trim((string) $config->get('ignore_patterns')),
      '#description'   => $this->t('Files whose <em>relative public:// path</em> matches any pattern will be ignored.'),
    ];

    $form['actions'] = [
      '#type'  => 'actions',
    ];
    $form['actions']['save'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate regex patterns compile.
    $raw = (string) $form_state->getValue('patterns', '');
    $parts = preg_split('/(\r\n|\n|\r|,)/', $raw) ?: [];
    foreach ($parts as $pattern) {
      $pattern = trim($pattern);
      if ($pattern === '') {
        continue;
      }
      if (@preg_match('#' . $pattern . '#', '') === FALSE) {
        $form_state->setErrorByName(
          'patterns',
          $this->t('Pattern %p is not a valid regex.', ['%p' => $pattern])
        );
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $this->configFactory()->getEditable('file_adoption.settings');
    $config
      ->set('ignore_patterns', trim((string) $form_state->getValue('patterns')))
      ->save();

    // Re‑evaluate ignored flags for all rows (fast SQL update using REGEXP).
    $patterns = $this->scanner->getIgnorePatterns();
    $table    = 'file_adoption_index';

    // 1. Default everything to NOT ignored.
    $this->db->update($table)
      ->fields(['is_ignored' => 0])
      ->execute();

    // 2. Mark rows matching ANY pattern.
    if ($patterns) {
      // Combine | alternation inside a single REGEXP for MySQL / MariaDB.
      $regexp = implode('|', array_map(fn($p) => '(' . $p . ')', $patterns));
      $this->db->update($table)
        ->fields(['is_ignored' => 1])
        ->condition('uri', $regexp, 'REGEXP')
        ->execute();
    }

    $this->messenger()->addStatus($this->t('Configuration saved. Ignore flags recomputed.'));
  }

  /**
   * Immediate adoption button handler.
   */
  public function adoptNow(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('file_adoption.settings');
    $limit  = (int) $config->get('items_per_run') ?? 20;
    $this->scanner->adoptUnmanaged($limit);
    $this->messenger()->addStatus($this->t('Adoption run complete.'));
    // Refresh form.
    $form_state->setRebuild(TRUE);
  }

}
