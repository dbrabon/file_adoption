<?php
declare(strict_types=1);
namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\Core\Form\FormState;

/**
 * Tests the FileAdoptionForm behavior.
 *
 * @group file_adoption
 */
class FileAdoptionFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Ensures buildForm uses saved orphan data.
   */
  public function testCronResultsUsedInForm() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/orphan.txt", 'x');

    // Use cron to populate the orphan table so the form reflects typical usage.
    file_adoption_cron();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('orphan.txt', $markup);
  }

  /**
   * Ensures buildForm does nothing when the orphan table is empty.
   */
  public function testFormNoAutoScanWhenEmpty() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/orphan.txt", 'x');

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $this->assertArrayNotHasKey('results_manage', $form);
    $this->assertEmpty($form_state->get('scan_results'));

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }

  /**
   * Ensures adoption works when results are loaded from the orphan table.
   */
  public function testAdoptFromSavedResults() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/orphan.txt", 'x');

    // Populate the orphan list via cron to mirror real behavior.
    file_adoption_cron();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('database'),
      $this->container->get('state')
    );

    $form = $form_object->buildForm([], $form_state);

    $results = $form_state->get('scan_results');
    $this->assertNotEmpty($results);
    $this->assertEquals(['public://orphan.txt'], $results['to_manage']);

    $form_state->setTriggeringElement(['#name' => 'adopt']);
    $form_object->submitForm($form, $form_state);

    $count = $this->container->get('database')
      ->select('file_managed')
      ->condition('uri', 'public://orphan.txt')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }

  /**
   * Ensures the directories list comes from the index table.
   */
  public function testDirectoryListingFromIndex() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    mkdir("$public/tmp1", 0777, TRUE);
    mkdir("$public/tmp2", 0777, TRUE);
    file_put_contents("$public/tmp1/keep.txt", 'a');
    file_put_contents("$public/tmp1/skip.log", 'b');
    file_put_contents("$public/tmp2/only.txt", 'c');

    // Register keep.txt as a managed file to verify the managed flag.
    $file = \Drupal\file\Entity\File::create([
      'uri' => 'public://tmp1/keep.txt',
      'filename' => 'keep.txt',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();

    $this->config('file_adoption.settings')->set('ignore_patterns', "*.log\ntmp2/*")->save();

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $records = $this->container->get('database')
      ->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri', 'ignored', 'managed'])
      ->execute()
      ->fetchAllAssoc('uri');

    $this->assertArrayHasKey('public://tmp1/keep.txt', $records);
    $this->assertEquals(0, $records['public://tmp1/keep.txt']->ignored);
    $this->assertEquals(1, $records['public://tmp1/keep.txt']->managed);
    $this->assertEquals(1, $records['public://tmp1/skip.log']->ignored);
    $this->assertEquals(0, $records['public://tmp1/skip.log']->managed);
    $this->assertEquals(1, $records['public://tmp2/only.txt']->ignored);
    $this->assertEquals(0, $records['public://tmp2/only.txt']->managed);

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $this->assertEquals('Directories', (string) $form['directories']['#title']);
    $markup = $form['directories']['list']['#markup'];
    $this->assertStringContainsString('tmp1/', $markup);
    $this->assertStringContainsString('skip.log', $markup);
    $this->assertStringContainsString('tmp2/', $markup);
    $this->assertStringContainsString('ignored', $markup);

    $pattern_markup = $form['directories']['patterns']['#markup'];
    $this->assertStringContainsString('*.log', $pattern_markup);
  }

  /**
   * Files flagged as ignored do not appear in the adoption list.
   */
  public function testIgnoredFilesExcludedFromAdoptionList() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/keep.txt", 'a');
    file_put_contents("$public/skip.log", 'b');

    // Initial cron run with no ignore patterns records both files as orphans.
    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();
    file_adoption_cron();

    // Update ignore patterns and rebuild the index without clearing orphans.
    $this->config('file_adoption.settings')->set('ignore_patterns', '*.log')->save();
    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('keep.txt', $markup);
    $this->assertStringNotContainsString('skip.log', $markup);
  }

  /**
   * Files ignored by pattern still show their directories in the listing.
   */
  public function testIgnoredPatternStillListsDirectory() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    mkdir("$public/dir", 0777, TRUE);
    file_put_contents("$public/dir/skip.txt", 'x');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();
    file_adoption_cron();

    $this->config('file_adoption.settings')->set('ignore_patterns', '*.txt')->save();
    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $manage_markup = $form['results_manage']['list']['#markup'] ?? '';
    $this->assertStringNotContainsString('skip.txt', $manage_markup);

    $dir_markup = $form['directories']['list']['#markup'];
    $this->assertStringContainsString('dir/', $dir_markup);
    $this->assertStringContainsString('skip.txt', $dir_markup);
  }

  /**
   * Additional orphans are reported when above the display limit.
   */
  public function testRemainingOrphanCountMessage() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    // Record all orphans using a high limit.
    $this->config('file_adoption.settings')->set('items_per_run', 20)->save();
    file_adoption_cron();

    // Display only two items per run.
    $this->config('file_adoption.settings')->set('items_per_run', 2)->save();

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('one.txt', $markup);
    $this->assertStringContainsString('two.txt', $markup);
    $this->assertStringNotContainsString('three.txt', $markup);
    $this->assertStringContainsString('1 additional file not shown', $markup);
  }

  /**
   * Orphan list updates when ignore patterns are removed.
   */
  public function testIgnorePatternRemovalRefreshesOrphans() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/keep.log", 'a');
    file_put_contents("$public/skip.txt", 'b');

    // Initially ignore txt files so only keep.log is recorded.
    $this->config('file_adoption.settings')->set('ignore_patterns', '*.txt')->save();
    file_adoption_cron();

    // Remove ignore patterns and rebuild the index.
    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();
    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('keep.log', $markup);
    $this->assertStringContainsString('skip.txt', $markup);

    // The orphan table should now contain both files.
    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count);
  }

  /**
   * Orphans are rebuilt from the index when the form loads.
   */
  public function testFormRebuildsFromIndex() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/index.txt", 'x');

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    // Ensure the orphan table starts empty.
    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('index.txt', $markup);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Directories deeper than the configured depth are omitted from the list.
   */
  public function testDirectoryDepthLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    mkdir("$public/level1/level2/level3", 0777, TRUE);
    file_put_contents("$public/level1/file.txt", 'a');
    file_put_contents("$public/level1/level2/file.txt", 'b');
    file_put_contents("$public/level1/level2/level3/file.txt", 'c');

    /** @var \Drupal\file_adoption\FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->buildIndex();

    $this->config('file_adoption.settings')->set('directory_depth', 1)->save();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $scanner,
      $this->container->get('database'),
      $this->container->get('state')
    );
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['directories']['list']['#markup'];
    $this->assertStringContainsString('level1/', $markup);
    $this->assertStringContainsString('level1/level2/', $markup);
    $this->assertStringNotContainsString('level1/level2/level3/', $markup);
  }

}
