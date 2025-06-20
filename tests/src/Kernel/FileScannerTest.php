<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests the FileScanner service.
 *
 * @group file_adoption
 */
class FileScannerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Tests ignore pattern parsing and scanning lists.
   */
  public function testScanning() {
    // Point the public files directory to a temporary location.
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    // Create files and directories.
    mkdir("$public/css", 0777, TRUE);
    file_put_contents("$public/example.txt", 'foo');
    file_put_contents("$public/css/skip.txt", 'bar');

    // Configure ignore patterns.
    $this->config('file_adoption.settings')->set('ignore_patterns', "css/*")->save();

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $patterns = $scanner->getIgnorePatterns();
    $this->assertEquals(['css/*'], $patterns);

    $results = $scanner->scanWithLists();
    $this->assertEquals(2, $results['files']);
    $this->assertEquals(1, $results['orphans']);
    $this->assertEquals(['public://example.txt'], $results['to_manage']);
  }

  /**
   * Tests adoption limit handling.
   */
  public function testAdoptionLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $result = $scanner->scanAndProcess(TRUE, 1);
    $this->assertEquals(2, $result['files']);
    $this->assertEquals(2, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(TRUE, 1);
    $this->assertEquals(2, $result['files']);
    $this->assertEquals(1, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(FALSE);
    $this->assertEquals(0, $result['orphans']);
  }

}
