<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Controller\PreviewController;
use Drupal\file_adoption\FileScanner;

/**
 * Tests preview markup for ignored directories.
 *
 * @group file_adoption
 */
class PreviewIgnoreMarkupTest extends KernelTestBase {

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
   * Ensures ignored directories are grayed out in the preview markup.
   */
  public function testIgnoredDirectoriesGrayed(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/ignored", 0777, TRUE);
    file_put_contents("$public/ignored/example.txt", 'x');
    mkdir("$public/visible", 0777, TRUE);
    file_put_contents("$public/visible/file.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', 'ignored/*')->save();

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state'),
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $markup = $data['markup'];

    $this->assertStringContainsString('<span style="color:gray">ignored/', $markup);
    $this->assertStringContainsString('visible/', $markup);
  }

}
