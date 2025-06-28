<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests caching of directories that contain no orphan files.
 *
 * @group file_adoption
 */
class NoOrphanDirectoryCacheTest extends KernelTestBase {

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
    $this->container->get('state')->delete(FileScanner::NO_ORPHAN_KEY);
  }

  /**
   * Ensures clean directories are cached and skipped on subsequent scans.
   */
  public function testCleanDirectoryCaching(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/clean", 0777, TRUE);
    file_put_contents("$public/clean/managed.txt", 'x');
    mkdir("$public/orphan", 0777, TRUE);
    file_put_contents("$public/orphan/file.txt", 'y');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    // Mark the file in the clean directory as managed.
    $scanner->adoptFile('public://clean/managed.txt');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $first = $scanner->scanChunk('', 50);
    $this->assertEquals(['public://orphan/file.txt'], $first['to_manage']);

    $cached = $this->container->get('state')->get(FileScanner::NO_ORPHAN_KEY) ?? [];
    $this->assertEquals(['clean'], $cached);

    // Adopt the orphan then add a new file to the cached directory.
    $scanner->adoptFiles($first['to_manage']);
    file_put_contents("$public/clean/new.txt", 'z');

    $second = $scanner->scanChunk('', 50);
    $this->assertEmpty($second['to_manage']);
  }

}

