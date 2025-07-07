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
      ->fields('h', ['nid', 'table_name', 'row_id', 'uri'])
      ->execute()
      ->fetchAssoc();
    $this->assertEquals([
      'nid' => 1,
      'table_name' => NULL,
      'row_id' => NULL,
      'uri' => 'public://test.txt',
    ], $record);
  }

  /**
   * Ensures links from multiple nodes are all recorded.
   */
  public function testRefreshStoresMultipleNodes() {
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
    $db->insert('node__body')->fields([
      'entity_id' => 2,
      'body_value' => '<a href="/sites/default/files/test.txt">file</a>',
    ])->execute();

    /** @var \Drupal\file_adoption\HardLinkScanner $scanner */
    $scanner = $this->container->get('file_adoption.hardlink_scanner');
    $scanner->refresh();

    $records = $db->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['nid', 'uri', 'table_name', 'row_id'])
      ->orderBy('nid')
      ->execute()
      ->fetchAll();

    $actual = [];
    foreach ($records as $record) {
      $actual[$record->nid] = [$record->uri, $record->table_name, $record->row_id];
    }

    $expected = [
      1 => ['public://test.txt', NULL, NULL],
      2 => ['public://test.txt', NULL, NULL],
    ];
    $this->assertEquals($expected, $actual);
  }

  /**
   * Ensures columns ending in _uri are scanned for links.
   */
  public function testRefreshScansUriColumns() {
    $schema = [
      'fields' => [
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'field_link_uri' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['entity_id'],
    ];
    $db = $this->container->get('database');
    $db->schema()->createTable('node__field_link', $schema);
    $db->insert('node__field_link')->fields([
      'entity_id' => 1,
      'field_link_uri' => '<a href="/sites/default/files/test.txt">file</a>',
    ])->execute();

    /** @var \Drupal\file_adoption\HardLinkScanner $scanner */
    $scanner = $this->container->get('file_adoption.hardlink_scanner');
    $scanner->refresh();

    $record = $db->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['nid', 'table_name', 'row_id', 'uri'])
      ->execute()
      ->fetchAssoc();
    $this->assertEquals([
      'nid' => 1,
      'table_name' => NULL,
      'row_id' => NULL,
      'uri' => 'public://test.txt',
    ], $record);
  }

  /**
   * Ensures links in multiple node tables are all recorded.
   */
  public function testRefreshScansMultipleTables() {
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
    $db->schema()->createTable('node_revision__body', $schema);
    $db->insert('node__body')->fields([
      'entity_id' => 1,
      'body_value' => '<a href="/sites/default/files/test.txt">file</a>',
    ])->execute();
    $db->insert('node_revision__body')->fields([
      'entity_id' => 2,
      'body_value' => '<a href="/sites/default/files/test.txt">file</a>',
    ])->execute();

    /** @var \Drupal\file_adoption\HardLinkScanner $scanner */
    $scanner = $this->container->get('file_adoption.hardlink_scanner');
    $scanner->refresh();

    $records = $db->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['nid', 'uri', 'table_name', 'row_id'])
      ->orderBy('nid')
      ->execute()
      ->fetchAll();

    $actual = [];
    foreach ($records as $record) {
      $actual[$record->nid] = [$record->uri, $record->table_name, $record->row_id];
    }

    $expected = [
      1 => ['public://test.txt', NULL, NULL],
      2 => ['public://test.txt', NULL, NULL],
    ];
    $this->assertEquals($expected, $actual);
  }

  /**
   * Confirms non-node tables with entity_id are scanned.
   */
  public function testRefreshScansNonNodeTable() {
    $schema = [
      'fields' => [
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'description' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['entity_id'],
    ];

    $db = $this->container->get('database');
    $db->schema()->createTable('custom_table', $schema);
    $db->insert('custom_table')->fields([
      'entity_id' => 3,
      'description' => '<img src="/sites/default/files/pic.jpg" />',
    ])->execute();

    /** @var \Drupal\file_adoption\HardLinkScanner $scanner */
    $scanner = $this->container->get('file_adoption.hardlink_scanner');
    $scanner->refresh();

    $record = $db->select('file_adoption_hardlinks', 'h')
      ->fields('h', ['table_name', 'row_id', 'nid', 'uri'])
      ->execute()
      ->fetchAssoc();

    $this->assertEquals([
      'table_name' => 'custom_table',
      'row_id' => '3',
      'nid' => NULL,
      'uri' => 'public://pic.jpg',
    ], $record);
  }

}
