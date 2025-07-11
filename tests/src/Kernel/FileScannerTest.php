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
   * Tests ignore pattern parsing and scanning lists.
   */
  public function testScanning() {
    // Point the public files directory to a temporary location.
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

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

    $results = $scanner->scanWithLists();
    $this->assertEquals(2, $results['files']);
    $this->assertEquals(1, $results['orphans']);
    $this->assertEquals(['public://example.txt'], $results['to_manage']);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Tests adoption limit handling.
   */
  public function testAdoptionLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $result = $scanner->scanAndProcess(TRUE, 1);
    $this->assertEquals(1, $result['files']);
    $this->assertEquals(1, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(TRUE, 1);
    $this->assertEquals(1, $result['files']);
    $this->assertEquals(1, $result['orphans']);
    $this->assertEquals(1, $result['adopted']);

    $result = $scanner->scanAndProcess(FALSE);
    $this->assertEquals(0, $result['orphans']);
  }

  /**
   * Ensures scanWithLists stops after the limit.
   */
  public function testScanWithListsLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $results = $scanner->scanWithLists(2);
    $this->assertEquals(2, $results['files']);
    $this->assertEquals(2, $results['orphans']);
    $this->assertCount(2, $results['to_manage']);
  }

  /**
   * Ensures symlinked files are skipped when ignore_symlinks is enabled.
   */
  public function testIgnoreSymlinks() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

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

    $results = $scanner->scanWithLists();
    $this->assertEquals(3, $results['files']);
    $this->assertEquals(3, $results['orphans']);
    $this->assertContains('public://link.txt', $results['to_manage']);

    $this->config('file_adoption.settings')->set('ignore_symlinks', TRUE)->save();

    $results = $scanner->scanWithLists();
    $this->assertEquals(2, $results['files']);
    $this->assertEquals(2, $results['orphans']);
    $this->assertNotContains('public://link.txt', $results['to_manage']);
  }

  /**
   * Ensures adoptFile does not create duplicates when the file is already managed.
   */
  public function testAdoptFileNoDuplicate() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'foo');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    // Prime the managed cache then create a managed file entity.
    $scanner->scanAndProcess(FALSE);

    $file = \Drupal\file\Entity\File::create([
      'uri' => 'public://example.txt',
      'filename' => 'example.txt',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    $added = $scanner->adoptFile('public://example.txt');
    $this->assertFalse($added);

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
    $this->config('system.file')->set('path.public', $public)->save();

    $path = "$public/time.txt";
    file_put_contents($path, 'x');
    $mtime = 1136073600; // Jan 1 2006.
    touch($path, $mtime);

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->adoptFile('public://time.txt');

    $file = \Drupal\file\Entity\File::load(1);
    $this->assertEquals($mtime, $file->getCreatedTime());
  }

  /**
   * Ensures adoptFile respects ignore patterns when called directly.
   */
  public function testAdoptFileRespectsIgnorePatterns() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/skip.txt", 'x');

    $this->config('file_adoption.settings')->set('ignore_patterns', '*.txt')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $result = $scanner->adoptFile('public://skip.txt');
    $this->assertFalse($result);

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
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/orphan.txt", 'x');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    // Record the orphan file.
    $scanner->scanWithLists();

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);

    // Adopt the orphan and ensure it is removed from the table.
    $scanner->adoptFile('public://orphan.txt');

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }


  /**
   * Ensures canonical URIs prevent duplicate adoption.
   */
  public function testCanonicalUriPreventsDuplicate() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

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

    $scanner->scanAndProcess();

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
    $this->config('system.file')->set('path.public', $public)->save();

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

    $scanner->buildIndex();

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
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/TestFile.txt", 'x');
    file_put_contents("$public/keep.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', '*test*')->save();

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $results = $scanner->scanWithLists();
    $this->assertEquals(1, $results['files']);
    $this->assertEquals(['public://keep.txt'], $results['to_manage']);
  }

  /**
   * Running buildIndex twice should not create duplicate records.
   */
  public function testBuildIndexIdempotent() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');

    $scanner->buildIndex();
    $first = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();

    $scanner->buildIndex();
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
