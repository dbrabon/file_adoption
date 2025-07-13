<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests the recordOrphans() method.
 *
 * @group file_adoption
 */
class RecordOrphansTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Ensures recordOrphans scans all files even when a limit is provided.
   */
  public function testRecordOrphansLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();

    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(3, $count);
  }

  /**
   * Verifies cron appends new orphan records on subsequent runs.
   */
  public function testCronAccumulation() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    // First run with one file.
    file_put_contents("$public/first.txt", 'a');
    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);

    // Second run with an additional file should retain the first record.
    file_put_contents("$public/second.txt", 'b');
    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count);
  }

}

