<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests the scanChunk method with very small time limits.
 *
 * @group file_adoption
 */
class ScanChunkTimeoutTest extends KernelTestBase {

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
   * Ensures scanning resumes correctly when time runs out.
   */
  public function testScanChunkTimeouts(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    // Build nested directories with many files.
    for ($i = 0; $i < 5; $i++) {
      $dir = "$public/dir$i/sub";
      mkdir($dir, 0777, TRUE);
      for ($j = 0; $j < 3; $j++) {
        file_put_contents("$dir/file{$i}_{$j}.txt", 'x');
      }
    }

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $resume = '';
    $seen = [];
    $runs = 0;
    do {
      $result = $scanner->scanChunk($resume, 1, 0.0001);
      $runs++;
      foreach ($result['to_manage'] as $uri) {
        $seen[] = $uri;
      }
      $resume = $result['resume'];
    } while ($resume !== '');

    sort($seen);

    $expected = [];
    for ($i = 0; $i < 5; $i++) {
      for ($j = 0; $j < 3; $j++) {
        $expected[] = "public://dir$i/sub/file{$i}_{$j}.txt";
      }
    }
    sort($expected);

    $this->assertGreaterThan(1, $runs, 'Scanning required multiple chunks');
    $this->assertEquals($expected, $seen);
  }

}
