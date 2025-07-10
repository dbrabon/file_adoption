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
    $this->config('system.file')->set('path.public', $public)->save();

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
    $this->config('system.file')->set('path.public', $public)->save();

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
    $this->config('system.file')->set('path.public', $public)->save();

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
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/tmp1", 0777, TRUE);
    mkdir("$public/tmp2", 0777, TRUE);
    file_put_contents("$public/tmp1/keep.txt", 'a');
    file_put_contents("$public/tmp1/skip.log", 'b');
    file_put_contents("$public/tmp2/only.txt", 'c');

    $this->config('file_adoption.settings')->set('ignore_patterns', "*.log\ntmp2/*")->save();

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

    $markup = $form['directories']['list']['#markup'];
    $this->assertStringContainsString('tmp1/', $markup);
    $this->assertStringContainsString('skip.log', $markup);
    $this->assertStringContainsString('tmp2/', $markup);
    $this->assertStringContainsString('ignored', $markup);
  }

}
