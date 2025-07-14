<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies default ignore patterns mark files as ignored on install.
 *
 * @group file_adoption
 */
class DefaultIgnorePatternsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Ensures the initial scan ignores files in default directories.
   */
  public function testDefaultPatternsIgnoredOnInstall() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    mkdir("$public/css", 0777, TRUE);
    mkdir("$public/js", 0777, TRUE);
    file_put_contents("$public/css/skip.txt", 'x');
    file_put_contents("$public/js/skip.txt", 'y');
    file_put_contents("$public/keep.txt", 'z');

    // Force cron to think a full scan recently occurred.
    $this->container->get('state')->set('file_adoption.last_full_scan', REQUEST_TIME);

    // Run cron to process the initial scan triggered by install.
    file_adoption_cron();

    $records = $this->container->get('database')
      ->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri', 'is_ignored'])
      ->execute()
      ->fetchAllKeyed();

    $this->assertEquals(3, count($records));
    $this->assertEquals(0, $records['public://keep.txt']);
    $this->assertEquals(1, $records['public://css/skip.txt']);
    $this->assertEquals(1, $records['public://js/skip.txt']);
  }
}
