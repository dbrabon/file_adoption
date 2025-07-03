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
     *   Maximum number of orphans to adopt. 0 means no limit.
     *
     * @return array
     *   An associative array with the keys 'files', 'orphans' and 'adopted'.
     */
    public function scanAndProcess(bool $adopt = TRUE, int $limit = 0) {
        $counts = ['files' => 0, 'orphans' => 0, 'adopted' => 0];
        $patterns = $this->getIgnorePatterns();
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');
        // Preload all managed file URIs.
        $this->loadManagedUris();
        // Only track whether the file is already managed.
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $counts;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
            if ($adopt && $limit > 0 && $counts['adopted'] >= $limit) {
                break;
            }
            if ($ignore_symlinks && $file_info->isLink()) {
                continue;
            }
            if (!$file_info->isFile()) {
                continue;
            }

            $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

            // Skip hidden files and directories.
            if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
                continue;
            }

            // Ignore based on configured patterns.
            $ignored = FALSE;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $relative_path)) {
                    $ignored = TRUE;
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $counts['files']++;

            $uri = $this->canonicalizeUri('public://' . $relative_path);

            if (isset($this->managedUris[$uri])) {
                continue;
            }

            $counts['orphans']++;

            if ($adopt) {
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
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');
        // Preload managed URIs for quick checks.
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
            if ($limit > 0 && count($results['to_manage']) >= $limit) {
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
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $results['files']++;

            $uri = $this->canonicalizeUri('public://' . $relative_path);

            if (!isset($this->managedUris[$uri])) {
                $results['orphans']++;
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
     * Filters a directory list based on unmanaged file presence.
     *
     * @param string[] $directories
     *   Directory paths relative to public:// with trailing slashes. The root
     *   directory should be represented as an empty string.
     * @param string[] $unmanaged
     *   List of unmanaged file URIs (public://...).
     *
     * @return string[]
     *   Subset of $directories that contain unmanaged files.
     */
    public function filterDirectoriesWithUnmanaged(array $directories, array $unmanaged): array {
        $map = array_fill_keys($directories, FALSE);
        foreach ($unmanaged as $uri) {
            $relative = str_starts_with($uri, 'public://') ? substr($uri, 9) : $uri;
            $dir = dirname($relative);
            if ($dir === '.') {
                $dir = '';
            }

            while (TRUE) {
                $key = $dir === '' ? '' : rtrim($dir, '/') . '/';
                if (isset($map[$key])) {
                    $map[$key] = TRUE;
                }
                if ($dir === '') {
                    break;
                }
                $dir = dirname($dir);
                if ($dir === '.') {
                    $dir = '';
                }
            }
        }

        return array_keys(array_filter($map));
    }

    /**
     * Scans the public files directory and records orphans to the database.
     *
     * This is optimized for cron when adoption is disabled. It clears the
     * orphan table before scanning and stops once the limit is reached.
     *
     * @param int $limit
     *   Maximum number of orphans to record. 0 means no limit.
     *
     * @return array
     *   Counts for 'files' and 'orphans'.
     */
    public function recordOrphans(int $limit = 0): array {
        $results = ['files' => 0, 'orphans' => 0];
        $patterns = $this->getIgnorePatterns();
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');

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
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $results['files']++;

            $uri = $this->canonicalizeUri('public://' . $relative_path);

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
     * Batch-compatible orphan recording step.
     *
     * This wrapper allows the Batch API to incrementally scan the public file
     * directory while tracking progress in the provided context sandbox.
     *
     * @param array $context
     *   Batch context array provided by the Batch API.
     */
    public function recordOrphansBatch(array &$context): void {
        $this->scanBatchStep($context);
    }

    /**
     * Batch step for scanning files and recording orphans.
     *
     * This is designed for use with the Drupal Batch API. The first invocation
     * gathers a list of all candidate files and clears the orphan table. Each
     * subsequent call processes a subset of the paths until complete.
     *
     * @param array $context
     *   Batch context array provided by the Batch API.
     */
    public function scanBatchStep(array &$context): void {
        $patterns = $this->getIgnorePatterns();
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');

        if (!isset($context['sandbox']['stack'])) {
            $context['sandbox']['stack'] = [];
            $context['sandbox']['processed'] = 0;
            $context['results'] = ['files' => 0, 'orphans' => 0];
            $managed_total = (int) $this->database->select('file_managed')
                ->countQuery()
                ->execute()
                ->fetchField();
            $approx = (int) ceil($managed_total * 1.05);
            if ($approx <= 0) {
                $approx = 1;
            }
            $context['sandbox']['approx_total'] = $approx;

            $public_realpath = $this->fileSystem->realpath('public://');
            $context['sandbox']['base'] = $public_realpath ?: '';
            $this->loadManagedUris();

            // Reset the orphan table at the start of a batch run.
            $this->database->truncate($this->orphanTable)->execute();

            if ($public_realpath && is_dir($public_realpath)) {
                $context['sandbox']['stack'][] = [
                    'relative' => '',
                    'index' => 0,
                ];
            }
        }

        $batch_size = (int) $this->configFactory
            ->get('file_adoption.settings')
            ->get('items_per_run');
        if ($batch_size <= 0) {
            $batch_size = 50;
        }

        $base = $context['sandbox']['base'];
        $processed_in_call = 0;

        while ($processed_in_call < $batch_size && !empty($context['sandbox']['stack'])) {
            $current_index = count($context['sandbox']['stack']) - 1;
            $node = &$context['sandbox']['stack'][$current_index];
            $relative_dir = $node['relative'];
            $absolute_dir = $relative_dir === '' ? $base : $base . DIRECTORY_SEPARATOR . $relative_dir;

            if (!isset($node['entries'])) {
                $entries = @scandir($absolute_dir);
                if ($entries === FALSE) {
                    array_pop($context['sandbox']['stack']);
                    continue;
                }
                $entries = array_values(array_diff($entries, ['.', '..']));
                $node['entries'] = $entries;
            }

            if ($node['index'] >= count($node['entries'])) {
                array_pop($context['sandbox']['stack']);
                continue;
            }

            $name = $node['entries'][$node['index']];
            $node['index']++;
            $relative = $relative_dir === '' ? $name : ($relative_dir . '/' . $name);
            $absolute = $absolute_dir . DIRECTORY_SEPARATOR . $name;

            if ($ignore_symlinks && is_link($absolute)) {
                continue;
            }

            if (is_dir($absolute)) {
                $dir_pattern = rtrim(str_replace('\\', '/', $relative), '/') . '/';
                $skip = FALSE;
                foreach ($patterns as $pattern) {
                    if ($pattern !== '' && fnmatch($pattern, $dir_pattern)) {
                        $skip = TRUE;
                        break;
                    }
                }
                if (!$skip && !preg_match('/(^|\/)(\.|\.{2})/', $relative)) {
                    $context['sandbox']['stack'][] = [
                        'relative' => str_replace('\\', '/', $relative),
                        'index' => 0,
                    ];
                }
                continue;
            }

            if (!is_file($absolute)) {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            if (preg_match('/(^|\/)(\.|\.{2})/', $relative)) {
                continue;
            }

            $ignored = FALSE;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $relative)) {
                    $ignored = TRUE;
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $context['results']['files']++;
            $context['sandbox']['processed']++;
            $uri = $this->canonicalizeUri('public://' . $relative);
            if (!isset($this->managedUris[$uri])) {
                $context['results']['orphans']++;
                $this->database->merge($this->orphanTable)
                    ->key('uri', $uri)
                    ->fields([
                        'uri' => $uri,
                        'timestamp' => time(),
                    ])
                    ->execute();
            }

            $processed_in_call++;
        }

        $approx_total = $context['sandbox']['approx_total'];
        $processed_total = $context['sandbox']['processed'];

        if (empty($context['sandbox']['stack'])) {
            $context['finished'] = 1;
        }
        else {
            $total_for_progress = $approx_total > 0 ? $approx_total : $processed_total;
            $context['finished'] = $total_for_progress > 0 ? min(1, $processed_total / $total_for_progress) : 1;
        }
    }

    /**
     * Counts files beneath the given relative path applying ignore patterns.
     *
     * @param string $relative_path
     *   Path relative to the public file directory.
     *
     * @return int
     *   Number of files found that do not match ignore patterns.
     */
    public function countFiles(string $relative_path = ''): int {
        $patterns = $this->getIgnorePatterns();
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return 0;
        }

        $relative_path = trim($relative_path, '/');
        $base = $relative_path === '' ? $public_realpath : $public_realpath . DIRECTORY_SEPARATOR . $relative_path;
        if (!is_dir($base)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file_info) {
            if ($ignore_symlinks && $file_info->isLink()) {
                continue;
            }
            if (!$file_info->isFile()) {
                continue;
            }

            $sub_path = str_replace('\\', '/', $iterator->getSubPathname());
            $relative = $relative_path === '' ? $sub_path : $relative_path . '/' . $sub_path;

            if (preg_match('/(^|\/)(\.|\.{2})/', $relative)) {
                continue;
            }

            $ignored = FALSE;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $relative)) {
                    $ignored = TRUE;
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Recursively lists directories and unmanaged files under a given path.
     *
     * @param string $relative_path
     *   Directory relative to the public file directory.
     *
     * @return array
     *   Array with keys 'directories' and 'unmanaged'. Directories are returned
     *   with trailing slashes and paths are relative to public://.
     */
    public function listUnmanagedRecursive(string $relative_path = ''): array {
        $results = ['directories' => [], 'unmanaged' => []];

        $patterns = $this->getIgnorePatterns();
        $ignore_symlinks = $this->configFactory->get('file_adoption.settings')->get('ignore_symlinks');

        $this->loadManagedUris();
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $results;
        }

        $relative_path = trim($relative_path, '/');
        $base = $relative_path === '' ? $public_realpath : $public_realpath . DIRECTORY_SEPARATOR . $relative_path;
        if (!is_dir($base)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file_info) {
            if ($ignore_symlinks && $file_info->isLink()) {
                continue;
            }

            $sub_path = str_replace('\\', '/', $iterator->getSubPathname());
            $relative = $relative_path === '' ? $sub_path : $relative_path . '/' . $sub_path;

            if ($relative === '') {
                continue;
            }

            if (preg_match('/(^|\/)(\.|\.{2})/', $relative)) {
                continue;
            }

            $ignored = FALSE;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $relative)) {
                    $ignored = TRUE;
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            if ($file_info->isDir()) {
                $results['directories'][] = rtrim($relative, '/') . '/';
            }
            elseif ($file_info->isFile()) {
                $uri = $this->canonicalizeUri('public://' . $relative);
                if (!isset($this->managedUris[$uri])) {
                    $results['unmanaged'][] = $uri;
                }
            }
        }

        return $results;
    }

    /**
     * Scans all directories and files for preview purposes.
     *
     * @return array
     *   Array with 'directories' and 'unmanaged' file URIs.
     */
    public function scanForPreview(): array {
        return $this->listUnmanagedRecursive('');
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

        try {
            if (!$this->managedLoaded) {
                $this->loadManagedUris();
            }

            // Re-check managed status in case files were added after
            // the managed list was loaded.
            if ($this->isManaged($uri)) {
                return FALSE;
            }

            // Skip if the file matches an ignore pattern.
            $patterns = $this->getIgnorePatterns();
            $relative = str_starts_with($uri, 'public://') ? substr($uri, 9) : $uri;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $relative)) {
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

            $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
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
