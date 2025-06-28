<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\file_adoption\Controller\PreviewController;
use Drupal\Core\Form\FormState;
use Drupal\file_adoption\FileScanner;

/**
 * Tests preview behavior with stored directory counts.
 *
 * @group file_adoption
 */
class PreviewStoredCountsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('state')->delete(FileScanner::INVENTORY_KEY);
  }

  /**
   * Ensures preview uses cached counts.
   */
  public function testPreviewUsesStoredCounts() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/a", 0777, TRUE);
    file_put_contents("$public/a/one.txt", '1');
    file_put_contents("$public/root.txt", 'r');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    // Run a quick scan to populate scan_results with dir_counts.
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);
    $form = [];
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    $stored = $this->container->get('state')->get('file_adoption.scan_results');
    $this->assertNotEmpty($stored['dir_counts']);

    // Add a new file after the scan which should not affect the preview.
    file_put_contents("$public/new.txt", 'n');

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);
    $this->assertEquals(2, $data['count']);
  }

  /**
   * Changing configuration should not trigger a rescan.
   */
  public function testPreviewIgnoresConfigChangesWhenCached() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/foo.txt", 'f');
    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);
    $form = [];
    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $form_object->submitForm($form, $form_state);

    // Change ignore patterns to something that would exclude foo.txt.
    $this->config('file_adoption.settings')->set('ignore_patterns', '*.txt')->save();

    $controller = new PreviewController(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state')
    );
    $data = json_decode($controller->preview()->getContent(), TRUE);

    // Should still report the original file count of 1.
    $this->assertEquals(1, $data['count']);
  }

}
