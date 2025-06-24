<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Controller\PreviewController;

/**
 * Tests preview generation with many files.
 *
 * @group file_adoption
 */
class PreviewLargeFilesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Ensures preview handles a large number of files.
   */
  public function testPreviewHandlesLargeFileSet() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    for ($i = 0; $i < 2000; $i++) {
      file_put_contents("$public/$i.txt", 'x');
    }

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);

    $this->assertEquals(2000, $data['count']);
    $this->assertNotEmpty($data['markup']);
  }

}
