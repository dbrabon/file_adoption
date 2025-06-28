<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Controller\PreviewController;
use Drupal\file_adoption\FileScanner;

/**
 * Tests folder depth configuration in preview output.
 *
 * @group file_adoption
 */
class PreviewFolderDepthTest extends KernelTestBase {

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
   * Ensures folder_depth affects directory listings.
   */
  public function testFolderDepthInPreview(): void {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a/b/c", 0777, TRUE);
    file_put_contents("$public/a/b/c/file.txt", 'x');

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state'),
    );

    $this->config('file_adoption.settings')->set('folder_depth', 1)->save();
    $this->container->get('state')->delete(FileScanner::INVENTORY_KEY);
    $data = json_decode($controller->dirs()->getContent(), TRUE);
    sort($data['dirs']);
    $this->assertEquals(['a'], $data['dirs']);

    $this->config('file_adoption.settings')->set('folder_depth', 2)->save();
    $this->container->get('state')->delete(FileScanner::INVENTORY_KEY);
    $data = json_decode($controller->dirs()->getContent(), TRUE);
    sort($data['dirs']);
    $this->assertEquals(['a', 'a/b'], $data['dirs']);
  }

}
