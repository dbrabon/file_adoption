<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;
use Drupal\file_adoption\Form\FileAdoptionForm;
use Drupal\Core\Form\FormState;

/**
 * Tests the directory depth configuration.
 *
 * @group file_adoption
 */
class DirectoryDepthTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Ensures directories deeper than the limit are omitted.
   */
  public function testDirectoryDepthLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save();

    mkdir("$public/level1/level2/level3", 0777, TRUE);
    file_put_contents("$public/level1/file.txt", 'a');
    file_put_contents("$public/level1/level2/file.txt", 'b');
    file_put_contents("$public/level1/level2/level3/file.txt", 'c');

    /** @var FileScanner $scanner */
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
