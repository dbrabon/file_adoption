<?php

namespace Drupal\file_adoption\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file_adoption\HardLinkScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for scanning hardcoded file links in nodes.
 */
class LinkScanController extends ControllerBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Hard link scanner service.
   *
   * @var \Drupal\file_adoption\HardLinkScanner
   */
  protected HardLinkScanner $scanner;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Creates a new controller instance.
   */
  public function __construct(Connection $database, HardLinkScanner $scanner, MessengerInterface $messenger) {
    $this->database = $database;
    $this->scanner = $scanner;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('file_adoption.hardlink_scanner'),
      $container->get('messenger')
    );
  }

  /**
   * Scans node text fields for hard coded file links.
   */
  public function scanAndAddUsage() {
    // Refresh hardlink references in the tracking table.
    $this->scanner->refresh();

    $records = $this->database->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['nid', 'uri'])
      ->orderBy('nid')
      ->execute()
      ->fetchAll();

    if ($records) {
      $summary = [];
      foreach ($records as $record) {
        $summary[$record->nid][] = $record->uri;
      }

      $lines = [];
      foreach ($summary as $nid => $uris) {
        $lines[] = $this->t('Node @nid: @uris', [
          '@nid' => $nid,
          '@uris' => implode(', ', $uris),
        ]);
      }

      $this->messenger->addWarning(implode("\n", $lines));
    }
    else {
      $this->messenger->addStatus($this->t('No hard coded links found.'));
    }

    return [
      '#markup' => $this->t('Hardlink scan complete.'),
    ];
  }

}
