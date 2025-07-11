<?php
declare(strict_types=1);

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;

/**
 * Scans public://, maintains file_adoption_index and adopts orphans.
 */
class FileScanner {

  protected FileSystemInterface $fileSystem;
  protected Connection          $db;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface        $logger;

  protected string $indexTable = 'file_adoption_index';

  /** Cached map uri ⇢ TRUE for known managed files. */
  protected array $managedUris = [];
  protected bool  $managedLoaded = FALSE;

  public function __construct(
    FileSystemInterface   $file_system,
    Connection            $database,
    ConfigFactoryInterface $config_factory,
    LoggerInterface        $logger,
  ) {
    $this->fileSystem    = $file_system;
    $this->db            = $database;
    $this->configFactory = $config_factory;
    $this->logger        = $logger;
  }

  /* ---------------------------------------------------------------------------
   *  Public API
   * ------------------------------------------------------------------------ */

  /**
   * Perform a recursive scan of public:// and (re‑)index every file found.
   * The operation is resumable – UI can read from the partially‑built table.
   */
  public function scanPublicFiles(): void {
    $public_path  = $this->fileSystem->realpath('public://') ?: '';
    if (!$public_path) {
      $this->logger->error('Unable to resolve public:// path.');
      return;
    }

    $settings       = $this->configFactory->get('file_adoption.settings');
    $ignore_symlinks = (bool) $settings->get('ignore_symlinks');
    $verbose         = (bool) $settings->get('verbose_logging');

    $patterns = $this->getIgnorePatterns();              // regex strings

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $public_path,
        \FilesystemIterator::SKIP_DOTS
      ),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file_info) {
      // Skip symlinks if configured.
      if ($ignore_symlinks && $file_info->isLink()) {
        continue;
      }

      if (!$file_info->isFile()) {
        continue;
      }

      // Relative path w.r.t. public://   (always forward‑slashes)
      $relative = str_replace('\\', '/', $iterator->getSubPathname());

      // Hidden dirs/files ( . or .. etc.)
      if (preg_match('@/(?:\.{1,2})(?:/|$)@', $relative)) {
        continue;
      }

      // Ignored?
      $ignored = $this->isIgnored($relative, $patterns);
      if ($ignored) {
        // Still store it; just flag is_ignored=1 so UI knows to omit.
      }

      // Canonical URI.
      $uri = 'public://' . ltrim($relative, '/');

      // Is it managed by Drupal already?
      $managed = $this->isManaged($uri);

      // Directory depth == number of / in path – 1 for files in root dir.
      $depth = substr_count($relative, '/');

      // UPSERT (insert or update) into index table.
      $this->db->merge($this->indexTable)
        ->key(['uri' => $uri])
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
          ['@uri' => $uri, '@m' => $managed ? '1' : '0',
           '@i'  => $ignored ? '1' : '0', '@d' => $depth]
        );
      }
    }
  }

  /**
   * Adopt up to $limit unmanaged + non‑ignored files.
   */
  public function adoptUnmanaged(int $limit = 20): void {
    $query = $this->db->select($this->indexTable, 'fi')
      ->fields('fi', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->range(0, $limit);

    $uris = $query->execute()->fetchCol();

    if (!$uris) {
      return;
    }

    foreach ($uris as $uri) {
      $this->adoptFile($uri);
    }
  }

  /* ---------------------------------------------------------------------------
   *  Helpers
   * ------------------------------------------------------------------------ */

  /** Return TRUE if $uri already exists in {file_managed}. */
  protected function isManaged(string $uri): bool {
    if (!$this->managedLoaded) {
      $this->managedUris = $this->db->select('file_managed', 'fm')
        ->fields('fm', ['uri'])
        ->execute()
        ->fetchAllKeyed(0, 0) ?: [];
      $this->managedLoaded = TRUE;
    }
    return isset($this->managedUris[$uri]);
  }

  /** pulls ignore_patterns (each line/CSV) as raw regex strings. */
  public function getIgnorePatterns(): array {
    $raw = trim((string) $this->configFactory
      ->get('file_adoption.settings')
      ->get('ignore_patterns'));
    if ($raw === '') {
      return [];
    }
    $parts = preg_split('/(\r\n|\n|\r|,)/', $raw);
    return array_values(array_filter(array_map('trim', $parts)));
  }

  /**
   * TRUE if $relative matches **any** regex in $patterns.
   */
  protected function isIgnored(string $relative, array $patterns): bool {
    foreach ($patterns as $regex) {
      // Patterns supplied WITHOUT delimiters; wrap in #…# by convention.
      if (@preg_match('#' . $regex . '#i', $relative)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Create a File entity for $uri so Drupal starts tracking it.
   */
  protected function adoptFile(string $uri): void {
    if (!file_exists($this->fileSystem->realpath($uri))) {
      // File disappeared – remove from index.
      $this->db->delete($this->indexTable)
        ->condition('uri', $uri)
        ->execute();
      return;
    }

    // Create a managed file entry.
    $file = File::create([
      'uri'      => $uri,
      'uid'      => 0,
      'status'   => 0,
    ]);
    $file->save();

    // Update the index row: file is now managed.
    $this->db->update($this->indexTable)
      ->fields(['is_managed' => 1])
      ->condition('uri', $uri)
      ->execute();

    $this->logger->notice('Adopted @uri into file_managed.', ['@uri' => $uri]);
  }
}
