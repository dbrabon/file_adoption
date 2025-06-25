<?php

namespace Drupal\file_adoption\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the File Adoption module.
 */
class FileAdoptionCommands extends DrushCommands {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the commands object.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Removes duplicate file_managed records by URI, keeping the newest entry.
   *
   * @command file_adoption:dedupe
   * @aliases fad-dedupe
   *
   * @usage drush file_adoption:dedupe
   *   Remove duplicate file_managed rows.
   */
  public function dedupe(): void {
    $duplicate_uris = $this->database->select('file_managed', 'fm')
      ->fields('fm', ['uri'])
      ->groupBy('uri')
      ->having('COUNT(fid) > 1')
      ->execute()
      ->fetchCol();

    if (!$duplicate_uris) {
      $this->logger()->notice('No duplicate file_managed entries found.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('file');
    foreach ($duplicate_uris as $uri) {
      $fids = $this->database->select('file_managed', 'fm')
        ->fields('fm', ['fid'])
        ->condition('uri', $uri)
        ->orderBy('fid', 'DESC')
        ->execute()
        ->fetchCol();

      $keep_fid = array_shift($fids);
      foreach ($fids as $fid) {
        if ($file = $storage->load($fid)) {
          $file->delete();
          $this->logger()->notice(dt('Deleted duplicate file @fid for @uri'), [
            '@fid' => $fid,
            '@uri' => $uri,
          ]);
        }
      }
      $this->logger()->notice(dt('Kept @fid for @uri'), [
        '@fid' => $keep_fid,
        '@uri' => $uri,
      ]);
    }
    $this->logger()->notice('Duplicate cleanup complete.');
  }

}
