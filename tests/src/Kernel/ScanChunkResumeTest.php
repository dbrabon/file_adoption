<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests the scanChunk method with resume tokens.
 *
 * @group file_adoption
 */
class ScanChunkResumeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Ensures scanning resumes correctly across chunks.
   */
  public function testScanChunkResumes() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $first = $scanner->scanChunk('', 1);
    $this->assertCount(1, $first['to_manage']);
    $this->assertNotEmpty($first['resume']);

    $second = $scanner->scanChunk($first['resume'], 1);
    $this->assertCount(1, $second['to_manage']);
    $this->assertEquals('', $second['resume']);

    $combined = array_merge($first['to_manage'], $second['to_manage']);
    sort($combined);

    $this->assertEquals([
      'public://one.txt',
      'public://two.txt',
    ], $combined);
  }

}
