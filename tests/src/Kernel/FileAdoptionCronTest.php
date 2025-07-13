<?php
declare(strict_types=1);

namespace Drupal\Tests\file_adoption\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file_adoption\FileScanner;

/**
 * Tests cron integration for the file_adoption module.
 *
 * @group file_adoption
 */
class FileAdoptionCronTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'file_adoption'];

  /**
   * Disable strict schema checks for configuration changes.
   */
  protected bool $strictConfigSchema = FALSE;

  /**
   * Tests that cron respects the item limit configuration.
   */
  public function testCronLimit() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/one.txt", '1');
    file_put_contents("$public/two.txt", '2');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', TRUE)
      ->set('items_per_run', 1)
      ->save();

    // First cron run should adopt only one file.
    file_adoption_cron();
    /** @var FileScanner $scanner */
    $scanner = $this->container->get('file_adoption.file_scanner');
    $scanner->scanPublicFiles();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);

    // Second cron run should adopt the remaining file.
    file_adoption_cron();
    $scanner->scanPublicFiles();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }

  /**
   * Verifies symlinks are ignored during cron when enabled.
   */
  public function testCronIgnoreSymlinks() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/real.txt", 'a');
    symlink("$public/real.txt", "$public/link.txt");

    $this->config('file_adoption.settings')
      ->set('enable_adoption', FALSE)
      ->set('ignore_symlinks', FALSE)
      ->save();

    // When symlinks are processed, two orphans are recorded.
    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count);

    $this->config('file_adoption.settings')->set('ignore_symlinks', TRUE)->save();

    // The original record remains and the real file entry is updated.
    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count);
  }

  /**
   * Ensures cron frequency is respected.
   */
  public function testCronFrequency() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/file.txt", 'x');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', FALSE)
      ->set('cron_frequency', 'weekly')
      ->save();

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME - 604800 - 1);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Ensures the "every" cron frequency runs each time.
   */
  public function testCronFrequencyEvery() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/file.txt", 'x');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', FALSE)
      ->set('cron_frequency', 'every')
      ->save();

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Ensures the monthly cron frequency is respected.
   */
  public function testCronFrequencyMonthly() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/file.txt", 'x');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', FALSE)
      ->set('cron_frequency', 'monthly')
      ->save();

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME - 2592000 - 1);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

  /**
   * Ensures the yearly cron frequency is respected.
   */
  public function testCronFrequencyYearly() {
    $public = $this->container->get('file_system')->getTempDirectory();
    $this->config('system.file')->set('path.public', $public)->save(TRUE);

    file_put_contents("$public/file.txt", 'x');

    $this->config('file_adoption.settings')
      ->set('enable_adoption', FALSE)
      ->set('cron_frequency', 'yearly')
      ->save();

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    \Drupal::state()->set('file_adoption.last_cron', REQUEST_TIME - 31536000 - 1);

    file_adoption_cron();
    $count = $this->container->get('database')
      ->select('file_adoption_index')
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $count);
  }

}
