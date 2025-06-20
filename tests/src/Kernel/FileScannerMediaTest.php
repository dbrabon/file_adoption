<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use ReflectionClass;

/**
 * Tests FileScanner integration with media entities.
 *
 * @group file_adoption
 */
class FileScannerMediaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'media', 'file_adoption'];

  /**
   * Ensures FileScanner detects files referenced by media.
   */
  public function testIsInMedia() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    // Create a managed file entity.
    file_put_contents("$public/example.txt", 'foo');
    $file = File::create([
      'uri' => 'public://example.txt',
      'filename' => 'example.txt',
      'status' => 1,
    ]);
    $file->save();

    // Create a media entity referencing the file.
    $media = Media::create([
      'bundle' => 'document',
      'uid' => 0,
      'status' => 1,
      'name' => 'example.txt',
      'field_media_document' => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $ref = new ReflectionClass($scanner);
    $method = $ref->getMethod('isInMedia');
    $method->setAccessible(TRUE);
    $result = $method->invoke($scanner, 'public://example.txt');

    $this->assertTrue($result);
  }

}
