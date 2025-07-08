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
   * Ensures orphan recording respects the limit.
   */
  public function testRecordOrphansLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->recordOrphans(2);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(2, $count);
  }

}

