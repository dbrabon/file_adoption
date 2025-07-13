<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;

/**
 * Tests that entity hooks maintain the managed flag in the index table.
 *
 * @group file_adoption
 */
class FileIndexHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Verifies insert and delete hooks update the managed flag.
   */
  public function testManagedFlagUpdates() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    // Create the actual file.
    file_put_contents("$public/example.txt", 'x');

    // Saving a file entity triggers hook_entity_insert().
    $file = File::create([
      'uri' => 'public://example.txt',
      'filename' => 'example.txt',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    $record = $this->container->get('database')
      ->select('file_adoption_index', 'fi')
      ->fields('fi', ['managed'])
      ->condition('uri', 'public://example.txt')
      ->execute()
      ->fetchObject();
    $this->assertNotEmpty($record);
    $this->assertEquals(1, $record->managed);

    // Deleting the entity should set managed to 0 but keep the row.
    $file->delete();

    $record = $this->container->get('database')
      ->select('file_adoption_index', 'fi')
      ->fields('fi', ['managed'])
      ->condition('uri', 'public://example.txt')
      ->execute()
      ->fetchObject();
    $this->assertNotEmpty($record);
    $this->assertEquals(0, $record->managed);
  }
}
