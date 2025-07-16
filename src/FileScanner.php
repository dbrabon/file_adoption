<?php
declare(strict_types=1);

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FileScanner {

  public function __construct(
    protected FileSystemInterface    $fileSystem,
    protected Connection             $db,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface        $logger,
    protected TimeInterface          $time,
  ) {}

  /* ------------------------------------------------------------------ */
  /** Full recursive scan of public:// (skips ignored dirs). */
  public function scanPublicFiles(): void {
    $public = $this->fileSystem->realpath('public://');
    if (!$public) {
      $this->logger->error('Unable to resolve public:// path.');
      return;
    }

    $cfg      = $this->configFactory->get('file_adoption.settings');
    $skipSym  = (bool) $cfg->get('ignore_symlinks');
    $verbose  = (bool) $cfg->get('verbose_logging');
    $patterns = $this->getIgnorePatterns();

    $private_prefix = NULL;
    $private_path = Settings::get('file_private_path');
    if (is_string($private_path)) {
      $normalized = str_replace('\\', '/', $private_path);
      if (str_starts_with($normalized, 'sites/default/files')) {
        $relative = trim(substr($normalized, strlen('sites/default/files')), '/');
        $private_prefix = rtrim($this->normalizeUri('public://' . $relative), '/') . '/';
      }
    }

    $start    = $this->time->getCurrentTime();

    $iter = new RecursiveIteratorIterator(
      new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS),
        fn($c) => $this->dirFilter($c, $public, $patterns, $skipSym)
      )
    );

    foreach ($iter as $f) {
      if (!$f->isFile()) {
        continue;
      }
      $rel   = str_replace('\\', '/', $iter->getSubPathname());
      $uri        = 'public://' . ltrim($rel, '/');
      $canonical   = $this->normalizeUri($uri);
      if ($private_prefix && str_starts_with($canonical, $private_prefix)) {
        continue;
      }
      $depth       = substr_count($rel, '/');
      $managed     = $this->isManaged($canonical);
      $raw_managed = $managed ? $this->getRawManagedUri($canonical) : NULL;

      $this->db->merge('file_adoption_index')
        ->key('uri', $canonical)
        ->fields([
          'timestamp'       => $start,
          'is_ignored'      => (int) $this->isIgnored($rel, $patterns),
          'is_managed'      => $managed ? 1 : 0,
          'directory_depth' => $depth,
          'managed_file_uri'=> $raw_managed,
        ])
        ->execute();

      if ($verbose) {
        $this->logger->debug('Indexed @uri', ['@uri' => $uri]);
      }
    }

    $this->db->delete('file_adoption_index')
      ->condition('timestamp', $start, '<')
      ->execute();
  }

  /* -------------------- helpers ------------------------------------- */
  protected function dirFilter($current, string $base, array $rx, bool $skipSym): bool {
    /** @var \SplFileInfo $current */
    if ($skipSym && $current->isLink()) {
      return false;
    }
    if ($current->isDir()) {
      $rel = str_replace('\\', '/', substr($current->getPathname(), strlen($base) + 1)) . '/';
      foreach ($rx as $p) {
        if (@preg_match('#' . $p . '#i', $rel)) {
          return false;        // skip entire subtree
        }
      }
    }
    return true;
  }

  protected array $managedUris      = [];
  protected bool  $loaded           = FALSE;

  /** Normalize a file URI for comparison. */
  public function normalizeUri(string $uri): string {
    $uri = str_replace('\\', '/', $uri);
    if (str_starts_with($uri, '/sites/default/files/')) {
      $uri = 'public://' . substr($uri, strlen('/sites/default/files/'));
    }
    return preg_replace('#^public:/+#', 'public://', $uri);
  }

  protected function isManaged(string $uri): bool {
    if (!$this->loaded) {
      $rows = $this->db->select('file_managed', 'fm')
        ->fields('fm', ['uri'])
        ->execute()
        ->fetchCol();
      foreach ($rows as $raw) {
        $this->managedUris[$this->normalizeUri($raw)] = $raw;
      }
      $this->loaded = TRUE;
    }
    return isset($this->managedUris[$this->normalizeUri($uri)]);
  }

  protected function getRawManagedUri(string $uri): ?string {
    if (!$this->loaded) {
      $this->isManaged($uri);
    }
    $norm = $this->normalizeUri($uri);
    return $this->managedUris[$norm] ?? NULL;
  }

  public function getIgnorePatterns(): array {
    $raw = trim((string) $this->configFactory->get('file_adoption.settings')->get('ignore_patterns'));
    return $raw === '' ? [] : array_values(array_filter(array_map('trim',
      preg_split('/(\r\n|\n|\r|,)/', $raw))));
  }

  public function isIgnored(string $rel, array $rx): bool {
    foreach ($rx as $p) {
      if (@preg_match('#' . $p . '#i', $rel)) {
        return true;
      }
    }
    return false;
  }

  public function adoptUnmanaged(int $limit = 20): void {
    $uris = $this->db->select('file_adoption_index', 'fi')
      ->fields('fi', ['uri'])
      ->condition('is_managed', 0)
      ->condition('is_ignored', 0)
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    foreach ($uris as $u) {
      $this->adoptFile($u);
    }
  }

  protected function adoptFile(string $uri): void {
    $real = $this->fileSystem->realpath($uri);
    if (!$real || !file_exists($real)) {
      $this->db->delete('file_adoption_index')->condition('uri', $uri)->execute();
      return;
    }
    $mtime = filemtime($real);
    $temporary = (bool) ($this->configFactory->get('file_adoption.settings')->get('adopt_temporary') ?? FALSE);
    $status    = $temporary ? 0 : 1;
    $f = File::create(['uri' => $uri, 'uid' => 0, 'status' => $status]);
    if ($mtime !== FALSE) {
      if (method_exists($f, 'setCreatedTime')) {
        $f->setCreatedTime($mtime);
      }
      else {
        $f->set('created', $mtime);
      }
    }
    $f->save();
    $this->db->update('file_adoption_index')
      ->fields(['is_managed' => 1])
      ->condition('uri', $uri)
      ->execute();
    $this->logger->notice('Adopted @uri', ['@uri' => $uri]);
  }
}
