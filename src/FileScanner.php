<?php

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Drupal\file\Entity\File;

/**
 * Service for scanning and adopting orphaned files in the public file directory.
 */
class FileScanner {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel for the file_adoption module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;


  /**
   * Cached list of URIs that are already managed.
   *
   * @var array
   */
  protected $managedUris = [];

  /**
   * Indicates whether managed URIs have been loaded.
   *
   * @var bool
   */
  protected $managedLoaded = FALSE;

  /**
   * Table name used to persist discovered orphan files.
   *
   * @var string
   */
  protected $orphanTable = 'file_adoption_orphans';

  /**
   * Constructs a FileScanner service object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for the file_adoption module.
   */
  public function __construct(FileSystemInterface $file_system, Connection $database, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->fileSystem = $file_system;
    $this->database = $database;
    $this->configFactory = $config_factory;
    // Use the provided logger channel (file_adoption).
    $this->logger = $logger;
  }

  /**
   * Retrieves and parses the ignore patterns from configuration.
   *
   * @return string[]
   *   An array of ignore pattern strings.
   */
  public function getIgnorePatterns() {
    $config = $this->configFactory->get('file_adoption.settings');
    $raw_patterns = $config->get('ignore_patterns');
    if (empty($raw_patterns)) {
      return [];
    }
    // Split the patterns string by newline and comma.
    $patterns = preg_split("/(\r\n|\n|\r|,)/", $raw_patterns);
    // Trim whitespace from each pattern.
    $patterns = array_map('trim', $patterns);
    // Filter out any empty patterns.
    $patterns = array_filter($patterns, function ($pattern) {
      return $pattern !== '';
    });
    return $patterns;
  }

  /**
   * Normalizes URIs using the public:// scheme.
   *
   * Collapses any redundant slashes directly after the scheme so that
   * variations like "public:///foo" become "public://foo".
   *
   * @param string $uri
   *   The URI to canonicalize.
   *
   * @return string
   *   The canonicalized URI.
   */
  private function canonicalizeUri(string $uri): string {
    if (str_starts_with($uri, 'public://')) {
      $uri = 'public://' . ltrim(substr($uri, 9), '/');
    }
    return $uri;
  }

  /**
   * Loads all managed file URIs into the local cache.
   */
  protected function loadManagedUris(): void {
    $this->managedUris = [];
    $this->managedLoaded = TRUE;
    $result = $this->database->select('file_managed', 'fm')
      ->fields('fm', ['uri'])
      ->execute();
    foreach ($result as $record) {
      $uri = $this->canonicalizeUri($record->uri);
      $this->managedUris[$uri] = TRUE;
    }
  }

  /**
   * Scans the public files directory and processes each file sequentially.
   *
   * This method avoids building large in-memory lists by evaluating each file
   * as it is encountered. If $adopt is TRUE, eligible files are immediately
   * adopted.
   *
   * @param bool $adopt
   *   Whether matching orphan files should be adopted.
   * @param int $limit
   *   Maximum number of orphans to adopt.
   *
   * @return array
   *   An associative array with the keys 'files', 'orphans' and 'adopted'.
   */
  public function scanAndProcess(bool $adopt = TRUE, int $limit = 0) {
    $counts = ['files' => 0, 'orphans' => 0, 'adopted' => 0];
    $patterns = $this->getIgnorePatterns();
    $settings = $this->configFactory->get('file_adoption.settings');
    $ignore_symlinks = $settings->get('ignore_symlinks');
    $verbose = (bool) $settings->get('verbose_logging');
    // Preload all managed file URIs.
    $this->loadManagedUris();
    // Only track whether the file is already managed.
    $public_realpath = $this->fileSystem->realpath('public://');

    if (!$public_realpath || !is_dir($public_realpath)) {
      return $counts;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file_info) {
      if ($adopt && $limit > 0 && $counts['adopted'] >= $limit) {
        break;
      }
      if ($ignore_symlinks && $file_info->isLink()) {
        continue;
      }

      $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

      if ($file_info->isDir()) {
        if ($verbose) {
          $dir_display = $relative_path === '' ? 'public://' : rtrim($relative_path, '/') . '/';
          $this->logger->debug('Scanning directory @directory', ['@directory' => $dir_display]);
        }
        continue;
      }

      if (!$file_info->isFile()) {
        continue;
      }

      // Skip hidden files and directories.
      if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
        continue;
      }

      // Ignore based on configured patterns.
      $ignored = FALSE;
      foreach ($patterns as $pattern) {
        if ($pattern !== '' && fnmatch($pattern, $relative_path)) {
          $ignored = TRUE;
          if ($verbose) {
            $this->logger->debug('Ignored file @file by pattern @pattern', ['@file' => $relative_path, '@pattern' => $pattern]);
          }
          break;
        }
      }
      if ($ignored) {
        continue;
      }

      $counts['files']++;

      $uri = $this->canonicalizeUri('public://' . $relative_path);
      if ($verbose) {
        $this->logger->debug('Scanning file @file', ['@file' => $uri]);
      }

      if (isset($this->managedUris[$uri])) {
        if ($verbose) {
          $this->logger->debug('Already managed: @file', ['@file' => $uri]);
        }
        continue;
      }

      $counts['orphans']++;

      if ($adopt) {
        if ($verbose) {
          $this->logger->debug('Adopting file @file', ['@file' => $uri]);
        }
        if ($this->adoptFile($uri)) {
          $counts['adopted']++;
          $this->managedUris[$uri] = TRUE;
        }
      }
    }

    return $counts;
  }

  /**
   * Scans the public files directory and returns lists for adoption.
   *
   * @param int $limit
   *   Maximum number of file paths to include in each list.
   *
   * @return array
   *   Associative array with keys 'files', 'orphans' and 'to_manage'.
   */
  public function scanWithLists(int $limit = 500) {
    $results = ['files' => 0, 'orphans' => 0, 'to_manage' => []];
    $patterns = $this->getIgnorePatterns();
    $settings = $this->configFactory->get('file_adoption.settings');
    $ignore_symlinks = $settings->get('ignore_symlinks');
    $verbose = (bool) $settings->get('verbose_logging');
    // Preload managed URIs for quick checks.
    $this->loadManagedUris();
    $public_realpath = $this->fileSystem->realpath('public://');

    // Clear existing records before each scan.
    $this->database->truncate($this->orphanTable)->execute();

    if (!$public_realpath || !is_dir($public_realpath)) {
      return $results;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file_info) {
      if ($limit > 0 && count($results['to_manage']) >= $limit) {
        break;
      }
      if ($ignore_symlinks && $file_info->isLink()) {
        continue;
      }

      $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

      if ($file_info->isDir()) {
        if ($verbose) {
          $dir_display = $relative_path === '' ? 'public://' : rtrim($relative_path, '/') . '/';
          $this->logger->debug('Scanning directory @directory', ['@directory' => $dir_display]);
        }
        continue;
      }

      if (!$file_info->isFile()) {
        continue;
      }

      if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
        continue;
      }

      $ignored = FALSE;
      foreach ($patterns as $pattern) {
        if ($pattern !== '' && fnmatch($pattern, $relative_path)) {
          $ignored = TRUE;
          if ($verbose) {
            $this->logger->debug('Ignored file @file by pattern @pattern', ['@file' => $relative_path, '@pattern' => $pattern]);
          }
          break;
        }
      }
      if ($ignored) {
        continue;
      }

      $results['files']++;

      $uri = $this->canonicalizeUri('public://' . $relative_path);
      if ($verbose) {
        $this->logger->debug('Scanning file @file', ['@file' => $uri]);
      }

      if (!isset($this->managedUris[$uri])) {
        $results['orphans']++;
        if ($verbose) {
          $this->logger->debug('Recording orphan file @file', ['@file' => $uri]);
        }
        // Persist to the orphan table for later processing.
        $this->database->merge($this->orphanTable)
          ->key('uri', $uri)
          ->fields([
            'uri' => $uri,
            'timestamp' => time(),
          ])
          ->execute();

        if (count($results['to_manage']) < $limit) {
          $results['to_manage'][] = $uri;
        }
      }
    }

    return $results;
  }

  /**
   * Scans the public files directory and records orphans to the database.
   *
   * This is optimized for cron when adoption is disabled. It clears the
   * orphan table before scanning and stops once the limit is reached.
   *
   * @param int $limit
   *   Maximum number of orphans to record.
   *
   * @return array
   *   Counts for 'files' and 'orphans'.
   */
  public function recordOrphans(int $limit = 0): array {
    $results = ['files' => 0, 'orphans' => 0];
    $patterns = $this->getIgnorePatterns();
    $settings = $this->configFactory->get('file_adoption.settings');
    $ignore_symlinks = $settings->get('ignore_symlinks');
    $verbose = (bool) $settings->get('verbose_logging');

    $this->loadManagedUris();
    $public_realpath = $this->fileSystem->realpath('public://');

    // Clear existing records before each scan.
    $this->database->truncate($this->orphanTable)->execute();

    if (!$public_realpath || !is_dir($public_realpath)) {
      return $results;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file_info) {
      if ($limit > 0 && $results['orphans'] >= $limit) {
        break;
      }
      if ($ignore_symlinks && $file_info->isLink()) {
        continue;
      }
      if (!$file_info->isFile()) {
        continue;
      }

      $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

      if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
        continue;
      }

      $ignored = FALSE;
      foreach ($patterns as $pattern) {
        if ($pattern !== '' && fnmatch($pattern, $relative_path)) {
          $ignored = TRUE;
          if ($verbose) {
            $this->logger->debug('Ignored file @file by pattern @pattern', ['@file' => $relative_path, '@pattern' => $pattern]);
          }
          break;
        }
      }
      if ($ignored) {
        continue;
      }

      $results['files']++;

      $uri = $this->canonicalizeUri('public://' . $relative_path);
      if ($verbose) {
        $this->logger->debug('Scanning file @file', ['@file' => $uri]);
      }

      if (!isset($this->managedUris[$uri])) {
        $results['orphans']++;
        $this->database->merge($this->orphanTable)
          ->key('uri', $uri)
          ->fields([
            'uri' => $uri,
            'timestamp' => time(),
          ])
          ->execute();
      }
    }

    return $results;
  }

  /**
   * Adopts (registers) the given files as managed file entities.
   *
   * @param string[] $file_uris
   *   Array of file URIs (public://...) to adopt.
   *
   * @return int
   *   The number of newly created items.
   */
  public function adoptFiles(array $file_uris) {
    $this->loadManagedUris();
    $count = 0;
    foreach ($file_uris as $uri) {
      $uri = $this->canonicalizeUri($uri);
      if ($this->adoptFile($uri)) {
        $count++;
        $this->managedUris[$uri] = TRUE;
      }
    }
    return $count;
  }

  /**
   * Adopts a single file.
   *
   * @param string $uri
   *   The file URI to adopt.
   *
   * @return bool
   *   TRUE if a new file entity was created, FALSE otherwise.
   */
  public function adoptFile(string $uri) {

    $uri = $this->canonicalizeUri($uri);
    $settings = $this->configFactory->get('file_adoption.settings');
    $verbose = (bool) $settings->get('verbose_logging');

    try {
      if (!$this->managedLoaded) {
        $this->loadManagedUris();
      }

      // Re-check managed status in case files were added after
      // the managed list was loaded.
      if ($this->isManaged($uri)) {
        if ($verbose) {
          $this->logger->debug('File already managed: @file', ['@file' => $uri]);
        }
        return FALSE;
      }

      // Skip if the file matches an ignore pattern.
      $patterns = $this->getIgnorePatterns();
      $relative = str_starts_with($uri, 'public://') ? substr($uri, 9) : $uri;
      foreach ($patterns as $pattern) {
        if ($pattern !== '' && fnmatch($pattern, $relative)) {
          if ($verbose) {
            $this->logger->debug('Ignored file @file by pattern @pattern', ['@file' => $relative, '@pattern' => $pattern]);
          }
          return FALSE;
        }
      }

      $realpath = $this->fileSystem->realpath($uri);
      $timestamp = $realpath && file_exists($realpath) ? filemtime($realpath) : time();

      $file = File::create([
        'uri' => $uri,
        'filename' => basename($uri),
        'status' => 1,
        'uid' => 0,
        'created' => $timestamp,
        'changed' => $timestamp,
      ]);
      $file->save();


      $this->managedUris[$uri] = TRUE;

      // Remove from orphan tracking table if present.
      $this->database->delete($this->orphanTable)
        ->condition('uri', $uri)
        ->execute();

      if ($verbose) {
        $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
      }
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to adopt file @file: @message', [
        '@file' => $uri,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a file URI already exists in the file_managed table.
   *
   * @param string $uri
   *   The file URI.
   *
   * @return bool
   *   TRUE if the file is managed, FALSE otherwise.
   */
  protected function isManaged(string $uri): bool {
    $uri = $this->canonicalizeUri($uri);

    if (!$this->managedLoaded) {
      $this->loadManagedUris();
    }

    return isset($this->managedUris[$uri]);
  }
}
