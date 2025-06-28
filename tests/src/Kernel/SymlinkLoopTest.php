<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;
use Drupal\file_adoption\Controller\PreviewController;

/**
 * Tests scanning with a symlink loop.
 *
 * @group file_adoption
 */
class SymlinkLoopTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * The temporary public files path used for testing.
   *
   * @var string
   */
  protected $publicPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('state')->delete(FileScanner::INVENTORY_KEY);

    $fs = $this->container->get('file_system');
    $base = $fs->getTempDirectory() . '/loop_test';
    mkdir($base);

    $this->publicPath = $base . '/public';
    mkdir($this->publicPath);

    file_put_contents($this->publicPath . '/file.txt', 'x');

    // Symlink that points to the parent directory.
    symlink('..', $this->publicPath . '/loop');

    $this->config('system.file')->set('path.public', $this->publicPath)->save();
    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();
  }

  /**
   * Ensures scanners and preview skip the symlink loop.
   */
  public function testSymlinkLoopHandling(): void {
    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $list = $scanner->scanWithLists();
    $this->assertEquals(1, $list['files']);
    $this->assertEquals(['public://file.txt'], $list['to_manage']);

    $chunk = $scanner->scanChunk('', 10);
    $this->assertEquals('', $chunk['resume']);
    $this->assertEquals(['public://file.txt'], $chunk['to_manage']);

    $controller = new PreviewController(
      $scanner,
      $this->container->get('file_system'),
      $this->container->get('state'),
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $this->assertEquals(1, $data['count']);
    $this->assertStringContainsString('file.txt', $data['markup']);
    $this->assertStringNotContainsString('loop', $data['markup']);
  }

}
