<?php
declare(strict_types=1);

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans public://, maintains file_adoption_index and adopts orphans.
 */
class FileScanner {

  protected FileSystemInterface   $fileSystem;
  protected Connection            $db;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface        $logger;

  protected string $indexTable = 'file_adoption_index';

  /** Lazy‑loaded set of managed URIs (hash = URI). */
  protected array $managedUris   = [];
  protected bool  $managedLoaded = FALSE;

  public function __construct(
    FileSystemInterface    $file_system,
    Connection             $database,
    ConfigFactoryInterface $config_factory,
    LoggerInterface        $logger,
  ) {
    $this->fileSystem    = $file_system;
    $this->db            = $database;
    $this->configFactory = $config_factory;
    $this->logger        = $logger;
  }

  /* --------------------------------------------------------------------- */
  /*  PUBLIC API                                                           */
  /* --------------------------------------------------------------------- */

  /**
   * Full recursive scan of public:// (skips dirs matching ignore regex).
   */
  public function scanPublicFiles(): void {
    $public_path = $this->fileSystem->realpath('public://');
    if (!$public_path) {
      $this->logger->error('Unable to resolve public:// path.');
      return;
    }

    $settings         = $this->configFactory->get('file_adoption.settings');
    $ignore_symlinks  = (bool) $settings->get('ignore_symlinks');
    $verbose          = (bool) $settings->get('verbose_logging');
    $patterns         = $this->getIgnorePatterns();

    // -------------------------------------------------------------------
    // Build an iterator that FILTERS OUT directories matching ignore regex
    // so we never recurse into them – big performance win on large trees.
    // -------------------------------------------------------------------
    $dirIter = new RecursiveDirectoryIterator(
      $public_path,
      \FilesystemIterator::SKIP_DOTS
    );

    $filter = new RecursiveCallbackFilterIterator(
      $dirIter,
      function ($current) use ($public_path, $patterns, $ignore_symlinks) {
        /** @var \SplFileInfo $current */
        if ($ignore_symlinks && $current->isLink()) {
          return false;
        }

        // Relative path from public://
        $relative = str_replace('\\', '/', substr(
          $current->getPathname(),
          strlen($public_path) + 1
        ));

        // If directory matches ignore → skip entire subtree.
        if ($current->isDir()) {
          foreach ($patterns as $rx) {
            if (@preg_match('#' . $rx . '#i', $relative . '/')) {
              return false; // do not recurse
            }
          }
          return true;
        }
        // Always include files; will be flagged ignored later if needed.
        return true;
      }
    );

    $iterator = new RecursiveIteratorIterator($filter);

    foreach ($iterator as $fileInfo) {
      if (!$fileInfo->isFile()) {
        continue;
      }

      $relative = str_replace('\\', '/', $iterator->getSubPathname());

      $ignored   = $this->isIgnored($relative, $patterns);
      $uri       = 'public://' . ltrim($relative, '/');
      $managed   = $this->isManaged($uri);
      $depth     = substr_count($relative, '/');

      // UPSERT row.
      $this->db->merge($this->indexTable)
        ->key  (['uri' => $uri])
        ->fields([
          'timestamp'       => \Drupal::time()->getCurrentTime(),
          'is_ignored'      => (int) $ignored,
          'is_managed'      => (int) $managed,
          'directory_depth' => $depth,
        ])
        ->execute();

      if ($verbose) {
        $this->logger->debug(
          'Indexed @uri (managed=@m ignored=@i depth=@d)',
          ['@uri' => $uri, '@m' => $managed, '@i' => $ignored, '@d' => $depth]
        );
      }
    }
  }

  /**
   * Adopt up to `$limit` unmanaged & non‑ignored files.
   */
  public function adoptUnmanaged(int $limit = 20): void {
    $uris = $this->db->select($this->indexTable, 'fi')
      ->fields('fi', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    foreach ($uris as $uri) {
      $this->adoptFile($uri);
    }
  }

  /* --------------------------------------------------------------------- */
  /*  HELPERS                                                              */
  /* --------------------------------------------------------------------- */

  /** TRUE if $uri exists in file_managed. */
  protected function isManaged(string $uri): bool {
    if (!$this->managedLoaded) {
      $this->managedUris = $this->db->select('file_managed', 'fm')
        ->fields('fm', ['uri'])
        ->execute()
        ->fetchAllKeyed(0, 0);
      $this->managedLoaded = TRUE;
    }
    return isset($this->managedUris[$uri]);
  }

  /** Return ignore patterns (array of raw regex strings). */
  public function getIgnorePatterns(): array {
    $raw = trim((string) $this->configFactory
      ->get('file_adoption.settings')
      ->get('ignore_patterns'));

    if ($raw === '') {
      return [];
    }
    return array_values(array_filter(
      array_map('trim', preg_split('/(\r\n|\n|\r|,)/', $raw))
    ));
  }

  /**
   * TRUE if $relative (file path without scheme) matches any ignore regex.
   */
  public function isIgnored(string $relative, array $patterns): bool {
    foreach ($patterns as $rx) {
      if (@preg_match('#' . $rx . '#i', $relative)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Create a Drupal File entity for $uri and mark as managed.
   */
  protected function adoptFile(string $uri): void {
    $real = $this->fileSystem->realpath($uri);
    if (!$real || !file_exists($real)) {
      // File vanished → drop index row.
      $this->db->delete($this->indexTable)
        ->condition('uri', $uri)->execute();
      return;
    }

    $file = File::create([
      'uri' => $uri,
      'uid' => 0,
      'status' => 0,
    ]);
    $file->save();

    $this->db->update($this->indexTable)
      ->fields(['is_managed' => 1])
      ->condition('uri', $uri)
      ->execute();

    $this->logger->notice('Adopted @uri into file_managed.', ['@uri' => $uri]);
  }
}
