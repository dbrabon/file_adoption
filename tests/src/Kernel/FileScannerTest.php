<?php
declare(strict_types=1);

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
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Tests ignore pattern parsing and scanning lists.
   */
  public function testScanning() {
    // Point the public files directory to a temporary location.
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

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

    $scanner->scanPublicFiles();

    $total = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $total);

    $orphans = $this->container->get('database')
      ->select('file_adoption_index')
      ->fields('file_adoption_index', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->execute()
      ->fetchCol();
    $this->assertEquals(['public://example.txt'], $orphans);
  }

  /**
   * Tests adoption limit handling.
   */
  public function testAdoptionLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $scanner->adoptUnmanaged(1);
    $remaining = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $remaining);

    $scanner->adoptUnmanaged(1);
    $remaining = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $remaining);
  }

  /**
   * Ensures scanWithLists stops after the limit.
   */
  public function testScanWithListsLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $scanner->adoptUnmanaged(2);
    $remaining = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $remaining);
  }

  /**
   * Ensures symlinked files are skipped when ignore_symlinks is enabled.
   */
  public function testIgnoreSymlinks() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    mkdir("$public/dir", 0777, TRUE);
    file_put_contents("$public/real.txt", 'a');
    file_put_contents("$public/dir/file.txt", 'b');

    // Symlink to a file and a directory.
    symlink("$public/real.txt", "$public/link.txt");
    symlink("$public/dir", "$public/dir_link");

    $this->config('file_adoption.settings')
      ->set('ignore_patterns', '')
      ->set('ignore_symlinks', FALSE)
      ->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(3, $count);
    $exists = $this->container->get('database')
      ->select('file_adoption_index')
      ->fields('file_adoption_index', ['uri'])
      ->condition('uri', 'public://link.txt')
      ->execute()
      ->fetchCol();
    $this->assertNotEmpty($exists);

    $this->config('file_adoption.settings')->set('ignore_symlinks', TRUE)->save();

    $scanner->scanPublicFiles();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count);
    $exists = $this->container->get('database')
      ->select('file_adoption_index')
      ->fields('file_adoption_index', ['uri'])
      ->condition('uri', 'public://link.txt')
      ->execute()
      ->fetchCol();
    $this->assertEmpty($exists);
  }

  /**
   * Ensures adoptFile does not create duplicates when the file is already managed.
   */
  public function testAdoptFileNoDuplicate() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/example.txt", 'foo');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    // Build the index then create a managed file entity.
    $scanner->scanPublicFiles();

    $file = \Drupal\file\Entity\File::create([
      'uri' => 'public://example.txt',
      'filename' => 'example.txt',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    $scanner->adoptUnmanaged();

    $count = $this->container->get('database')
      ->select('file_managed')
      ->condition('uri', 'public://example.txt')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Verifies adopted files use the file modification time as the created date.
   */
  public function testAdoptFileUsesFileTimestamp() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    $path = "$public/time.txt";
    file_put_contents($path, 'x');
    $mtime = 1136073600; // Jan 1 2006.
    touch($path, $mtime);

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $scanner->adoptUnmanaged();

    $file = \Drupal\file\Entity\File::load(1);
    $this->assertGreaterThanOrEqual($mtime, $file->getCreatedTime());
  }

  /**
   * Ensures adoptFile respects ignore patterns when called directly.
   */
  public function testAdoptFileRespectsIgnorePatterns() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/skip.txt", 'x');

    $this->config('file_adoption.settings')->set('ignore_patterns', '*.txt')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $scanner->adoptUnmanaged();

    $count = $this->container->get('database')
      ->select('file_managed')
      ->condition('uri', 'public://skip.txt')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }

  /**
   * Ensures orphan records are removed after adopting a file.
   */
  public function testAdoptFileRemovesOrphan() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/orphan.txt", 'x');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    // Record the orphan file.
    $scanner->scanPublicFiles();

    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('uri', 'public://orphan.txt')
      ->fields('file_adoption_index', ['is_managed'])
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    // Adopt the orphan and ensure it is marked managed.
    $scanner->adoptUnmanaged();

    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('uri', 'public://orphan.txt')
      ->fields('file_adoption_index', ['is_managed'])
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }


  /**
   * Ensures canonical URIs prevent duplicate adoption.
   */
  public function testCanonicalUriPreventsDuplicate() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/name.jpg", 'x');

    // Create a managed file with redundant slashes in the URI.
    $file = \Drupal\file\Entity\File::create([
      'uri' => 'public:///name.jpg',
      'filename' => 'name.jpg',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $scanner->adoptUnmanaged();

    $count = $this->container->get('database')
      ->select('file_managed')
      ->condition('filename', 'name.jpg')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Ensures buildIndex records ignored files correctly.
   */
  public function testBuildIndexRecordsIgnoredFlag() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/keep.txt", 'a');
    file_put_contents("$public/skip.log", 'b');

    // Mark keep.txt as a managed file so the index can flag it accordingly.
    $file = \Drupal\file\Entity\File::create([
      'uri' => 'public://keep.txt',
      'filename' => 'keep.txt',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    $this->config('file_adoption.settings')->set('ignore_patterns', '*.log')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();

    $records = $this->container->get('database')
      ->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri', 'ignored', 'managed'])
      ->execute()
      ->fetchAllAssoc('uri');

    $this->assertEquals(['public://keep.txt', 'public://skip.log'], array_keys($records));
    $this->assertEquals(0, $records['public://keep.txt']->ignored);
    $this->assertEquals(1, $records['public://keep.txt']->managed);
    $this->assertEquals(1, $records['public://skip.log']->ignored);
    $this->assertEquals(0, $records['public://skip.log']->managed);
  }

  /**
   * Verifies ignore patterns are case-insensitive.
   */
  public function testIgnorePatternsCaseInsensitive() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/TestFile.txt", 'x');
    file_put_contents("$public/keep.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', '*test*')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $orphans = $this->container->get('database')
      ->select('file_adoption_index')
      ->fields('file_adoption_index', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->execute()
      ->fetchCol();
    $this->assertEquals(['public://keep.txt'], $orphans);
  }

  /**
   * Running buildIndex twice should not create duplicate records.
   */
  public function testBuildIndexIdempotent() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->scanPublicFiles();
    $first = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();

    $scanner->scanPublicFiles();
    $second = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();

    $uris = $this->container->get('database')
      ->select('file_adoption_index')
      ->fields('file_adoption_index', ['uri'])
      ->execute()
      ->fetchCol();

    $this->assertEquals($first, $second);
    $this->assertEquals(count($uris), count(array_unique($uris)));
  }

}
