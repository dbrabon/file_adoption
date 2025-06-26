<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\file_adoption\Controller\PreviewController;
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
  public function testQuickScan() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'foo');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    $results = $form_state->get('scan_results');
    $this->assertEquals(['public://example.txt'], $results['to_manage']);
    $this->assertEquals(['' => 1], $results['dir_counts']);
    $this->assertNull($this->container->get('state')->get('file_adoption.scan_progress'));
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
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    // Build the form again to inspect the rendered list.
    $form_state->setTriggeringElement([]);
    $form = $form_object->buildForm([], $form_state);
    $markup = $form['results_manage']['list']['#markup'];

    $this->assertEquals('file-adoption-results', $form['results_manage']['#attributes']['id']);

    preg_match_all('/<li>/', $markup, $matches);
    $this->assertCount(2, $matches[0]);
    $this->assertStringNotContainsString('three.txt', $markup);
  }

  /**
   * Ensures long scans fall back to the batch process.
   */
  public function testBatchScan() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'foo');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    $this->assertNull($form_state->get('scan_results'));
    $this->assertNotEmpty($this->container->get('state')->get('file_adoption.scan_progress'));

    $context = [];
    file_adoption_scan_batch_step($context);

    $results = $this->container->get('state')->get('file_adoption.scan_results');
    $this->assertEquals(['public://example.txt'], $results['to_manage']);
    $this->assertEquals(['' => 1], $results['dir_counts']);
  }

  /**
   * Ensures quick scans fall back to a batch when the time limit is exceeded.
   */
  public function testQuickScanTimeout() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'foo');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    putenv('FILE_ADOPTION_SCAN_LIMIT=0');

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    putenv('FILE_ADOPTION_SCAN_LIMIT');

    $this->assertNull($form_state->get('scan_results'));
    $this->assertNotEmpty($this->container->get('state')->get('file_adoption.scan_progress'));
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

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $markup = $data['markup'];

    $this->assertStringContainsString('thousand/', $markup);
    $this->assertStringContainsString('(1)', $markup);
    $this->assertStringNotContainsString('(2)', $markup);
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

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $this->assertEquals(1, $data['count']);
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

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $this->assertEquals(1, $data['count']);
  }

  /**
   * Ensures the items per run value is capped at 5000.
   */
  public function testItemsPerRunCap() {
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('items_per_run', 99999);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    $this->assertEquals(5000, $this->config('file_adoption.settings')->get('items_per_run'));
  }

}
