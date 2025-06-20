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
      $this->container->get('file_system')
    );
    $form_object->submitForm($form, $form_state);

    $results = $form_state->get('scan_results');
    $this->assertEquals(['public://example.txt'], $results['to_manage']);
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
      $this->container->get('file_system')
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
      $this->container->get('file_system')
    );

    $form = $form_object->buildForm([], $form_state);

    $markup = $form['preview']['markup']['#markup'] ?? $form['preview']['list']['#markup'];

    $this->assertStringContainsString('thousand/', $markup);
    $this->assertStringContainsString('(1)', $markup);
    $this->assertStringNotContainsString('(2)', $markup);
  }

}
