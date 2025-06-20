<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests cron integration for the file_adoption module.
 *
 * @group file_adoption
 */
class FileAdoptionCronTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Tests that cron respects the item limit configuration.
   */
  public function testCronLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', TRUE)
      ->set('items_per_run', 1)
      ->save();

    // First cron run should adopt only one file.
    file_adoption_cron();
    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $result = $scanner->scanAndProcess(FALSE);
    $this->assertEquals(1, $result['orphans']);

    // Second cron run should adopt the remaining file.
    file_adoption_cron();
    $result = $scanner->scanAndProcess(FALSE);
    $this->assertEquals(0, $result['orphans']);
  }

}

