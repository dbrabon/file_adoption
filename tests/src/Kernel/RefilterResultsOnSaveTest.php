<?php

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\Core\Form\FormState;
use Drupal\file_adoption\FileScanner;

/**
 * Tests re-filtering scan results on configuration save.
 *
 * @group file_adoption
 */
class RefilterResultsOnSaveTest extends KernelTestBase {

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
   * Ensures saved scan results are filtered when ignore patterns change.
   */
  public function testResultsFilteredOnSave() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    file_put_contents("$public/keep.txt", 'a');
    file_put_contents("$public/skip.txt", 'b');

    $this->config('file_adoption.settings')->set('ignore_patterns', '')->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#name' => 'quick_scan']);

    $form_object = new FileAdoptionForm(
      $this->container->get('file_adoption.file_scanner'),
      $this->container->get('file_system'),
      $this->container->get('state'),
    );
    $form_object->submitForm($form, $form_state);

    $state = $this->container->get('state');
    $results = $state->get('file_adoption.scan_results');
    sort($results['to_manage']);
    $this->assertEquals([
      'public://keep.txt',
      'public://skip.txt',
    ], $results['to_manage']);
    $this->assertEquals(['' => 2], $results['dir_counts']);

    // Update ignore patterns to exclude skip.txt and save configuration.
    $form_state2 = new FormState();
    $form_state2->setValue('ignore_patterns', 'skip.txt');
    $form_state2->setValue('enable_adoption', 0);
    $form_state2->setValue('items_per_run', 100);
    $form_state2->setTriggeringElement(['#name' => 'op']);

    $form_object->submitForm([], $form_state2);

    $filtered = $state->get('file_adoption.scan_results');
    $this->assertEquals(['public://keep.txt'], $filtered['to_manage']);
    $this->assertEquals(['' => 1], $filtered['dir_counts']);
    $this->assertEquals(['public://keep.txt'], $form_state2->get('scan_results')['to_manage']);
    $this->assertTrue($form_state2->isRebuild());
  }

}

