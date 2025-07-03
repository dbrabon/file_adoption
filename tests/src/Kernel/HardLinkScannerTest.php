<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the HardLinkScanner service.
 *
 * @group file_adoption
 */
class HardLinkScannerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Verifies link references are stored during a refresh.
   */
  public function testRefreshStoresLinks() {
    $schema = [
      'fields' => [
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'body_value' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['entity_id'],
    ];
    $db = $this->container->get('database');
    $db->schema()->createTable('node__body', $schema);
    $db->insert('node__body')->fields([
      'entity_id' => 1,
      'body_value' => '<a href="/sites/default/files/test.txt">file</a>',
    ])->execute();

    /** @var \Drupal\file_adoption\HardLinkScanner $scanner */
    $scanner = $this->container->get('file_adoption.hardlink_scanner');
    $scanner->refresh();

    $record = $db->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['nid', 'uri'])
      ->execute()
      ->fetchAssoc();
    $this->assertEquals(['nid' => 1, 'uri' => 'public://test.txt'], $record);
  }

}
