<?php
declare(strict_types=1);

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;

class FileAdoptionManager {

  public function __construct(
    protected FileScanner          $scanner,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface         $state,
    protected Connection             $db,
    protected FileSystemInterface    $fileSystem,
    protected TimeInterface          $time,
  ) {}

  /**
   * Executes cron operations for the file adoption module.
   */
  public function runCron(): void {
    $config = $this->configFactory->get('file_adoption.settings');

    if ($this->state->get('file_adoption.needs_initial_scan')) {
      $this->scanner->scanPublicFiles();
      $this->state->set('file_adoption.last_full_scan', $this->time->getCurrentTime());
      $this->state->delete('file_adoption.needs_initial_scan');
    }

    $interval = ((int) ($config->get('scan_interval_hours') ?? 24)) * 3600;
    $last     = (int) $this->state->get('file_adoption.last_full_scan', 0);

    if ($interval === 0 || $this->time->getCurrentTime() - $last >= $interval) {
      $this->scanner->scanPublicFiles();
      $this->state->set('file_adoption.last_full_scan', $this->time->getCurrentTime());
    }

    if ($config->get('enable_adoption')) {
      $this->scanner->adoptUnmanaged((int) ($config->get('items_per_run') ?? 20));
    }
  }

  /**
   * Updates the index when a file entity is created.
   */
  public function onEntityInsert(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'file') {
      return;
    }
    $uri = $entity->getFileUri();
    if (!str_starts_with($uri, 'public://')) {
      return;
    }
    $relative = substr($uri, 9);
    $ignored  = $this->scanner->isIgnored($relative, $this->scanner->getIgnorePatterns());

    $this->db->merge('file_adoption_index')
      ->key('uri', $uri)
      ->fields([
        'timestamp'       => $this->time->getCurrentTime(),
        'is_ignored'      => $ignored ? 1 : 0,
        'is_managed'      => 1,
        'directory_depth' => substr_count($relative, '/'),
      ])
      ->execute();
  }

  /**
   * Updates the index when a file entity is deleted.
   */
  public function onEntityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'file') {
      return;
    }
    $uri = $entity->getFileUri();
    if (!str_starts_with($uri, 'public://')) {
      return;
    }
    $relative = substr($uri, 9);
    $ignored  = $this->scanner->isIgnored($relative, $this->scanner->getIgnorePatterns());
    $real     = $this->fileSystem->realpath($uri);

    if ($real && file_exists($real)) {
      $this->db->merge('file_adoption_index')
        ->key('uri', $uri)
        ->fields([
          'timestamp'       => $this->time->getCurrentTime(),
          'is_ignored'      => $ignored ? 1 : 0,
          'is_managed'      => 0,
          'directory_depth' => substr_count($relative, '/'),
        ])
        ->execute();
    }
    else {
      $this->db->delete('file_adoption_index')
        ->condition('uri', $uri)
        ->execute();
    }
  }
}
