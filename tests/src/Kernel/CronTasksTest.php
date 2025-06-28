<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests additional cron tasks and locking.
 *
 * @group file_adoption
 */
class CronTasksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('state')->delete(FileScanner::INVENTORY_KEY);
  }

  /**
   * Ensures inventory and example discovery flags are processed.
   */
  public function testCronProcessesPendingTasks(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a", 0777, true);
    file_put_contents("$public/a/example.txt", 'x');

    $state = $this->container->get('state');
    $state->set('file_adoption.inventory_pending', true);
    $state->set('file_adoption.examples_pending', true);

    file_adoption_cron();

    $inventory = $state->get('file_adoption.dir_inventory')['dirs'] ?? [];
    $examples = $state->get('file_adoption.examples_cache')['examples'] ?? [];

    $this->assertEmpty($inventory);
    $this->assertEmpty($examples);
    $this->assertTrue($state->get('file_adoption.inventory_pending'));
    $this->assertTrue($state->get('file_adoption.examples_pending'));
  }

  /**
   * Ensures an existing lock prevents work from running.
   */
  public function testCronLockPreventsOverlap(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');

    $state = $this->container->get('state');
    $state->set('file_adoption.cron_lock', time());

    file_adoption_cron();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $result = $scanner->scanAndProcess(false);
    $this->assertEquals(1, $result['orphans']);
  }

}

