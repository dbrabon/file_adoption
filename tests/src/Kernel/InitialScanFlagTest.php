<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that an initial scan is triggered on first cron run.
 *
 * @group file_adoption
 */
class InitialScanFlagTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Ensures a scan occurs when the install flag is present.
   */
  public function testInitialScanFlag() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    // Install hook should set the flag.
    $state = $this->container->get('state');
    $this->assertTrue($state->get('file_adoption.needs_initial_scan'));

    file_put_contents("$public/test.txt", 'x');

    // Force cron to think a full scan recently occurred.
    $state->set('file_adoption.last_full_scan', REQUEST_TIME);

    // Run cron, which should scan despite the interval because of the flag.
    file_adoption_cron();

    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);

    // Flag should be cleared after the run.
    $this->assertNull($state->get('file_adoption.needs_initial_scan'));
  }
}
