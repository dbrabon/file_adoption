<?php

namespace Drupal\file_adoption\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file_adoption\FileScanner;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for scanning orphaned files.
 */
class FileAdoptionScanCommands extends DrushCommands {

  /**
   * The file scanner service.
   *
   * @var \Drupal\file_adoption\FileScanner
   */
  protected $fileScanner;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the command object.
   */
  public function __construct(FileScanner $fileScanner, ConfigFactoryInterface $configFactory) {
    parent::__construct();
    $this->fileScanner = $fileScanner;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('config.factory'),
    );
  }

  /**
   * Scans the public files directory for orphaned files.
   *
   * @command file_adoption:scan
   * @aliases fad-scan
   *
   * @option adopt Adopt discovered files immediately.
   * @option limit Maximum number of files to process (capped at 500).
   *
   * @usage drush file_adoption:scan
   *   List orphaned files that would be adopted.
   * @usage drush file_adoption:scan --adopt --limit=10
   *   Adopt up to ten orphaned files.
   */
  public function scan(array $options = ['adopt' => FALSE, 'limit' => NULL]): void {
    $adopt = (bool) ($options['adopt'] ?? FALSE);
    $limit = $options['limit'];
    if ($limit === NULL) {
      $limit = (int) $this->configFactory->get('file_adoption.settings')->get('items_per_run');
    }
    if ($limit < 0) {
      $limit = 0;
    }
    elseif ($limit > 500) {
      $limit = 500;
    }

    if ($adopt) {
      $result = $this->fileScanner->scanAndProcess(TRUE, $limit);
      $this->logger()->notice('Scanned @files file(s); adopted @count orphan(s).', [
        '@files' => $result['files'],
        '@count' => $result['adopted'],
      ]);
    }
    else {
      $result = $this->fileScanner->scanWithLists($limit);
      $this->logger()->notice('Scanned @files file(s); found @orphans orphan(s).', [
        '@files' => $result['files'],
        '@orphans' => $result['orphans'],
      ]);
      if (!empty($result['to_manage'])) {
        foreach ($result['to_manage'] as $uri) {
          $this->output()->writeln($uri);
        }
      }
    }
  }

}
