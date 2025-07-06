<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Controller\LinkScanController;

/**
 * Tests the LinkScanController output.
 *
 * @group file_adoption
 */
class LinkScanControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Ensures the scan controller reports detected links.
   */
  public function testScanAndAddUsageMessage() {
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

    \Drupal::messenger()->deleteAll();

    $controller = LinkScanController::create($this->container);
    $controller->scanAndAddUsage();

    $messages = \Drupal::messenger()->messagesByType('warning');
    $this->assertNotEmpty($messages);
    $message = (string) reset($messages);
    $this->assertStringContainsString('1', $message);
    $this->assertStringContainsString('public://test.txt', $message);
  }

}

