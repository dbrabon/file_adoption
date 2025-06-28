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
    $this->assertEquals(['' => 1], $results['dir_counts']);
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
    $this->assertEquals(1, $result['files']);
    $this->assertEquals(1, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(TRUE, 1);
    $this->assertEquals(1, $result['files']);
    $this->assertEquals(1, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(FALSE);
    $this->assertEquals(0, $result['orphans']);
  }

  /**
   * Ensures scanWithLists stops after the limit.
   */
  public function testScanWithListsLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $results = $scanner->scanWithLists(2);
    $this->assertEquals(2, $results['files']);
    $this->assertEquals(2, $results['orphans']);
    $this->assertCount(2, $results['to_manage']);
    $this->assertEquals(['' => 3], $results['dir_counts']);
  }

  /**
   * Tests countFiles respects nested ignore patterns.
   */
  public function testCountFilesWithNestedIgnore() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/thousand/testfiles", 0777, TRUE);
    file_put_contents("$public/thousand/testfiles/ignore.txt", 'x');
    file_put_contents("$public/thousand/keep.txt", 'y');

    $this->config('file_adoption.settings')
      ->set('ignore_patterns', "thousand/testfiles/*")
      ->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $count = $scanner->countFiles('thousand');
    $this->assertEquals(1, $count);
  }

  /**
   * Tests countFilesByDirectory aggregates counts correctly.
   */
  public function testCountFilesByDirectory() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a/b", 0777, TRUE);
    file_put_contents("$public/file.txt", 'x');
    file_put_contents("$public/a/file_a.txt", 'y');
    file_put_contents("$public/a/b/file_b.txt", 'z');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $counts = $scanner->countFilesByDirectory();
    $this->assertEquals(3, $counts['']);
    $this->assertEquals(2, $counts['a']);
    $this->assertEquals(1, $counts['a/b']);

    $this->config('file_adoption.settings')->set('ignore_patterns', 'a/b/*')->save();
    $scanner = $this->container->get('file_adoption.file_scanner');
    $counts = $scanner->countFilesByDirectory();
    $this->assertEquals(2, $counts['']);
    $this->assertEquals(1, $counts['a']);
    $this->assertArrayNotHasKey('a/b', $counts);
  }

  /**
   * Tests directory inventory caching and depth handling.
   */
  public function testDirectoryInventory() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a/b", 0777, TRUE);
    mkdir("$public/c", 0777, TRUE);

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $dirs = $scanner->inventoryDirectories(1);
    sort($dirs);
    $this->assertEquals(['a', 'c'], $dirs);

    $cached = $scanner->getDirectoryInventory(1);
    sort($cached);
    $this->assertEquals(['a', 'c'], $cached);

    \Drupal::state()->set(FileScanner::INVENTORY_KEY, [
      'dirs' => ['x'],
      'depth' => 1,
      'timestamp' => time() - 86500,
    ]);

    $fresh = $scanner->getDirectoryInventory(1);
    sort($fresh);
    $this->assertEquals(['a', 'c'], $fresh);
  }

  /**
   * Tests firstFile, countFilesIn and collectFolderData helpers.
   */
  public function testFolderHelpers(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a", 0777, TRUE);
    mkdir("$public/b", 0777, TRUE);
    file_put_contents("$public/a/file.txt", 'a');
    file_put_contents("$public/b/example.log", 'b');
    file_put_contents("$public/b/keep.txt", 'c');

    $this->config('file_adoption.settings')->set('ignore_patterns', '*.log')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $first = $scanner->firstFile('b');
    $this->assertEquals('keep.txt', $first);

    $count = $scanner->countFilesIn('b');
    $this->assertEquals(1, $count);

    $data = $scanner->collectFolderData(['a', 'b']);
    $this->assertEquals(['a' => 'file.txt', 'b' => 'keep.txt'], $data['examples']);
    $this->assertEquals(['a' => 1, 'b' => 1], $data['counts']);
  }

}
