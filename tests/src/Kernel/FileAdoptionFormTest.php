<?php

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
   * Tests scanning action in the form submit handler.
   */
  public function testFormScan() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'foo');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm($form, $form_state);

    $results = $form_state->get('scan_results');
    $this->assertEquals(['public://example.txt'], $results['to_manage']);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Tests list display respects the configured limit.
   */
  public function testFormListLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    file_put_contents("$public/three.txt", '3');

    $this->config('file_adoption.settings')
      ->set('ignore_patterns', '')
      ->set('items_per_run', 2)
      ->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm($form, $form_state);

    // Build the form again to inspect the rendered list.
    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);
    $markup = $form['results_manage']['list']['#markup'];

    preg_match_all('/<li>/', $markup, $matches);
    $this->assertCount(2, $matches[0]);
    $this->assertStringNotContainsString('three.txt', $markup);
  }

  /**
   * Tests directory count preview uses ignore patterns.
   */
  public function testPreviewDirectoryCounts() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/thousand/testfiles", 0777, TRUE);
    file_put_contents("$public/thousand/testfiles/ignore.txt", 'x');
    file_put_contents("$public/thousand/keep.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', 'thousand/testfiles/*')->save();

    $form_state = new FormState();

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );

    $form = $form_object->buildForm([], $form_state);

    $markup = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];

    $this->assertStringContainsString('thousand/', $markup);
    $this->assertStringContainsString('(1)', $markup);
    $this->assertStringNotContainsString('(2)', $markup);

    // The subdirectory should be listed with no count since its files are
    // ignored.
    $this->assertStringContainsString('testfiles/', $markup);
    $this->assertStringNotMatchesRegularExpression('/testfiles\/+\s*\(\d+\)/', $markup);
  }

  /**
   * Tests main preview count uses ignore patterns.
   */
  public function testPreviewMainCountUsesIgnorePatterns() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/ignored", 0777, TRUE);
    file_put_contents("$public/ignored/file.txt", 'x');
    file_put_contents("$public/keep.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', 'ignored/*')->save();

    $form_state = new FormState();

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );

    $form = $form_object->buildForm([], $form_state);

    $title = (string) $form['preview']['#title'];
    $this->assertStringContainsString('(1)', $title);
    $this->assertStringNotContainsString('(2)', $title);
  }

  /**
   * Tests preview count with multiple ignore patterns.
   */
  public function testPreviewCountMultiplePatterns() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/skip.txt", 'x');
    file_put_contents("$public/sample.log", 'y');
    file_put_contents("$public/keep.txt", 'z');

    $this->config('file_adoption.settings')
      ->set('ignore_patterns', "skip.txt\n*.log")
      ->save();

    $form_state = new FormState();

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );

    $form = $form_object->buildForm([], $form_state);

    $title = (string) $form['preview']['#title'];
    $this->assertStringContainsString('(1)', $title);
    $this->assertStringNotContainsString('(2)', $title);
  }

  /**
   * Ensures buildForm uses cron data when available.
   */
  public function testCronResultsUsedInForm() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/orphan.txt", 'x');

    // Record orphans as cron would.
    $this->container->get('file_adoption.file_scanner')->recordOrphans();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );

    $form = $form_object->buildForm([], $form_state);

    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('orphan.txt', $markup);
  }

  /**
   * Ensures buildForm does not scan or modify the database when empty.
   */
  public function testFormNoAutoScanWhenEmpty() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/orphan.txt", 'x');

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
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
   * Ensures preview highlights directories containing unmanaged files.
   */
  public function testPreviewHighlightsUnmanagedDirectories() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/foo", 0777, TRUE);
    file_put_contents("$public/foo/file.txt", 'x');
    mkdir("$public/bar", 0777, TRUE);
    file_put_contents("$public/bar/skip.txt", 'y');

    $this->config('file_adoption.settings')->set('ignore_patterns', 'bar/*')->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm([], $form_state);

    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];

    $this->assertStringContainsString('<strong>public://', $markup);
    $this->assertStringContainsString('<strong>foo/', $markup);
    $this->assertStringNotContainsString('<strong>bar/', $markup);
  }

  /**
   * Verifies symlinked files do not trigger highlights when ignored.
   */
  public function testPreviewHighlightRespectsIgnoreSymlinks() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/real.txt", 'a');
    symlink("$public/real.txt", "$public/link.txt");

    $this->config('file_adoption.settings')->set('ignore_symlinks', TRUE)->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm([], $form_state);

    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);

    $markup = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];

    $this->assertStringNotContainsString('<strong>public://', $markup);
  }

  /**
   * Ensures symlink paths appear in the preview list.
   */
  public function testSymlinkPreviewList() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/real.txt", 'a');
    symlink("$public/real.txt", "$public/link.txt");

    $this->config('file_adoption.settings')->set('ignore_symlinks', FALSE)->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm([], $form_state);

    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);

    $symlink_markup = $form['preview']['symlinks']['list']['#markup'];

    $this->assertStringContainsString('link.txt', $symlink_markup);
    $this->assertStringNotContainsString('(ignored)', $symlink_markup);
  }

  /**
   * Ensures ignored symlinks are flagged in the preview list.
   */
  public function testSymlinkPreviewIgnoredFlag() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/real.txt", 'a');
    symlink("$public/real.txt", "$public/link.txt");

    $this->config('file_adoption.settings')->set('ignore_symlinks', TRUE)->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm([], $form_state);

    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);

    $symlink_markup = $form['preview']['symlinks']['list']['#markup'];

    $this->assertStringContainsString('link.txt', $symlink_markup);
    $this->assertStringContainsString('(ignored)', $symlink_markup);
  }

  /**
   * Ensures adoption works when results are loaded from the orphan table.
   */
  public function testAdoptFromSavedResults() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/orphan.txt", 'x');

    // Record orphaned files as cron or batch scanning would.
    $this->container->get('file_adoption.file_scanner')->recordOrphans();

    $form_state = new FormState();
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );

    // Build the form to load results from the database.
    $form = $form_object->buildForm([], $form_state);

    $results = $form_state->get('scan_results');
    $this->assertNotEmpty($results);
    $this->assertEquals(['public://orphan.txt'], $results['to_manage']);

    // Trigger the adopt action using the loaded results.
    $form_state->setTriggeringElement(['#name' => 'adopt']);
    $form_object->submitForm($form, $form_state);

    // Verify the file was adopted and orphan records cleared.
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
   * Tests refreshing hardlink references via the form.
   */
  public function testRefreshLinksAction() {
    // Create a mock node body table.
    $schema = [
      'fields' => [
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'body_value' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['entity_id'],
    ];
    $this->container->get('database')->schema()->createTable('node__body', $schema);
    $this->container->get('database')->insert('node__body')->fields([
      'entity_id' => 1,
      'body_value' => '<img src="/sites/default/files/sample.txt" />',
    ])->execute();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'refresh_links']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('file_adoption.hardlink_scanner')
    );
    $form_object->submitForm([], $form_state);

    $count = $this->container->get('database')
      ->select('file_adoption_hardlinks')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

}
