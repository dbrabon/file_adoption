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
      FileAdoptionForm::batchScanOperation($context);
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

}
