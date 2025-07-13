<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that uninstall cleans up state.
 *
 * @group file_adoption
 */
class UninstallStateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Ensures state values are removed on uninstall.
   */
  public function testStateRemovedOnUninstall() {
    $state = \Drupal::state();
    $state->set('file_adoption.last_full_scan', 123);
    $state->set('file_adoption.last_cron', 456);

    \Drupal::service('module_installer')->uninstall(['file_adoption']);

    $this->assertNull($state->get('file_adoption.last_full_scan'));
    $this->assertNull($state->get('file_adoption.last_cron'));
  }
}
