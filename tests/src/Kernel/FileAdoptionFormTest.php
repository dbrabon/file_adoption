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

    $form_object = new FileAdoptionForm($this->container->get('file_adoption.file_scanner'));
    $form_object->submitForm($form, $form_state);

    $results = $form_state->get('scan_results');
    $this->assertEquals(['public://example.txt'], $results['to_manage']);
  }

}
