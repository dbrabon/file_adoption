<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\Core\Form\FormState;

/**
 * Tests batch scanning via the configuration form.
 *
 * @group file_adoption
 */
class FileAdoptionBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Tests that batch scanning records orphans and preview highlights them.
   */
  public function testBatchScanRecordsOrphans() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');
    mkdir("$public/sub", 0777, TRUE);
    file_put_contents("$public/sub/three.txt", '3');

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );

    // Trigger the Batch Scan submit handler which initializes the batch.
    $form_object->submitForm([], $form_state);

    // Execute the batch operations manually.
    $context = [];
    do {
      FileAdoptionForm::batchScanStep($context);
    } while (empty($context['finished']));
    FileAdoptionForm::batchScanFinished(TRUE, $context['results'], []);

    // Ensure the orphan records were created.
    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(3, $count);

    // Build the form again to view the results.
    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state);
    $markup = $form['results_manage']['list']['#markup'];
    $this->assertStringContainsString('one.txt', $markup);
    $this->assertStringContainsString('two.txt', $markup);
    $this->assertStringContainsString('three.txt', $markup);

    // Trigger a preview using the saved results to confirm highlighting.
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'scan']);
    $uris = $this->container->get('database')
      ->select('file_adoption_orphans', 'fo')
      ->fields('fo', ['uri'])
      ->orderBy('timestamp', 'ASC')
      ->execute()
      ->fetchCol();
    $form_state->set('scan_results', [
      'files' => count($uris),
      'orphans' => count($uris),
      'to_manage' => $uris,
    ]);
    $form = $form_object->buildForm([], $form_state);
    $preview = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];
    $this->assertStringContainsString('<strong>public://', $preview);
  }

  /**
   * Ensures the batch operation records all orphans when many files exist.
   */
  public function testBatchScanHandlesLargeDirectories() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    for ($i = 0; $i < 120; $i++) {
      file_put_contents("$public/file{$i}.txt", (string) $i);
    }

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $form_object->submitForm([], $form_state);

    $context = [];
    FileAdoptionForm::batchScanStep($context);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(20, $count);
    $this->assertEmpty($context['finished']);

    while (empty($context['finished'])) {
      FileAdoptionForm::batchScanStep($context);
    }
    FileAdoptionForm::batchScanFinished(TRUE, $context['results'], []);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(120, $count);
  }

  /**
   * Verifies ignore patterns are respected during batch scanning.
   */
  public function testBatchScanRespectsIgnorePatterns() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/keep.txt", 'k');
    file_put_contents("$public/skip.log", 's');
    mkdir("$public/ignored", 0777, TRUE);
    file_put_contents("$public/ignored/test.txt", 'i');

    $this->config('file_adoption.settings')
      ->set('ignore_patterns', "*.log\nignored/*")
      ->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $form_object->submitForm([], $form_state);

    $context = [];
    do {
      FileAdoptionForm::batchScanStep($context);
    } while (empty($context['finished']));
    FileAdoptionForm::batchScanFinished(TRUE, $context['results'], []);

    $uris = $this->container->get('database')
      ->select('file_adoption_orphans', 'fo')
      ->fields('fo', ['uri'])
      ->execute()
      ->fetchCol();

    $this->assertEquals(['public://keep.txt'], $uris);
  }

  /**
   * Ensures batch processing uses the configured batch size.
   */
  public function testBatchUsesConfiguredBatchSize() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    for ($i = 0; $i < 30; $i++) {
      file_put_contents("$public/file{$i}.txt", (string) $i);
    }

    $this->config('file_adoption.settings')->set('items_per_run', 10)->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $form_object->submitForm([], $form_state);

    $context = [];
    $iterations = 0;
    FileAdoptionForm::batchScanStep($context);
    $iterations++;

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(10, $count);
    $this->assertEmpty($context['finished']);

    while (empty($context['finished'])) {
      FileAdoptionForm::batchScanStep($context);
      $iterations++;
    }
    FileAdoptionForm::batchScanFinished(TRUE, $context['results'], []);

    $count = $this->container->get('database')
      ->select('file_adoption_orphans')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(30, $count);
    $this->assertGreaterThanOrEqual(3, $iterations);
  }

  /**
   * Ensures preview is displayed after a batch run when the query flag is set.
   */
  public function testPreviewShownAfterBatchRedirect() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/example.txt", 'x');

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $form_object->submitForm([], $form_state);

    $context = [];
    do {
      FileAdoptionForm::batchScanStep($context);
    } while (empty($context['finished']));
    FileAdoptionForm::batchScanFinished(TRUE, $context['results'], []);

    // Simulate redirect with query parameter.
    $request = \Drupal::request();
    $request->query->set('batch_complete', 1);

    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state);

    $request->query->remove('batch_complete');

    $preview = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];
    $this->assertStringContainsString('<strong>public://', $preview);
  }

  /**
   * Ensures initialization uses the approximate total based on file_managed.
   */
  public function testBatchInitializationUsesApproximateTotal() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    for ($i = 0; $i < 5; $i++) {
      file_put_contents("$public/file{$i}.txt", (string) $i);
    }

    // Insert managed file entries to simulate existing files.
    $database = $this->container->get('database');
    for ($i = 0; $i < 10; $i++) {
      $database->insert('file_managed')->fields([
        'fid' => $i + 1,
        'uid' => 0,
        'filename' => "existing{$i}.txt",
        'uri' => "public://existing{$i}.txt",
        'filemime' => 'text/plain',
        'filesize' => 0,
        'status' => 1,
        'created' => REQUEST_TIME,
        'changed' => REQUEST_TIME,
      ])->execute();
    }

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'batch_scan']);
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system')
    );
    $form_object->submitForm([], $form_state);

    $context = [];
    FileAdoptionForm::batchScanStep($context);

    $this->assertEquals(11, $context['sandbox']['approx_total']);
  }
}
