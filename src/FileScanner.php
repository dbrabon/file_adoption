<?php

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Drupal\file\Entity\File;

/**
 * Service for scanning and adopting orphaned files in the public file directory.
 */
class FileScanner {

    /**
     * State key for storing managed file URIs.
     */
    public const STATE_KEY = 'file_adoption.managed_cache';

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
     * Drupal state service for caching managed URIs.
     *
     * @var \Drupal\Core\State\StateInterface
     */
    protected $state;

    /**
     * Timestamp of the last change to file_managed table cached.
     *
     * @var int
     */
    protected $managedChanged = 0;

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
    public function __construct(FileSystemInterface $file_system, Connection $database, ConfigFactoryInterface $config_factory, LoggerInterface $logger, StateInterface $state) {
        $this->fileSystem = $file_system;
        $this->database = $database;
        $this->configFactory = $config_factory;
        // Use the provided logger channel (file_adoption).
        $this->logger = $logger;
        $this->state = $state;
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
     * Filters file URIs against configured ignore patterns.
     *
     * @param string[] $uris
     *   Array of file URIs (public://...) to check.
     *
     * @return string[]
     *   URIs that do not match any ignore pattern.
     */
    public function filterUris(array $uris): array {
        $patterns = $this->getIgnorePatterns();
        $filtered = [];
        foreach ($uris as $uri) {
            $relative = str_replace('public://', '', $uri);

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
            if (!$ignored) {
                $filtered[] = $uri;
            }
        }

        return $filtered;
    }

    /**
     * Loads all managed file URIs into the local cache.
     *
     * @param bool $reset
     *   (optional) Whether to force a reload even if the list has already been
     *   populated. Defaults to FALSE.
     */
    protected function loadManagedUris(bool $reset = FALSE): void {
        if ($reset) {
            $this->managedLoaded = FALSE;
        }
        if ($this->managedLoaded) {
            return;
        }

        $cache = $this->state->get(self::STATE_KEY) ?? [];
        $cached_changed = $cache['changed'] ?? 0;

        $current_changed = (int) $this->database->select('file_managed', 'fm')
            ->fields('fm', ['changed'])
            ->orderBy('changed', 'DESC')
            ->range(0, 1)
            ->execute()
            ->fetchField() ?: 0;

        if (!empty($cache['uris']) && $cached_changed === $current_changed) {
            $this->managedUris = $cache['uris'];
            $this->managedChanged = $cached_changed;
        }
        else {
            $this->managedUris = [];
            $result = $this->database->select('file_managed', 'fm')
                ->fields('fm', ['uri'])
                ->execute();
            foreach ($result as $record) {
                $this->managedUris[$record->uri] = TRUE;
            }
            $this->managedChanged = $current_changed;
            $this->state->set(self::STATE_KEY, [
                'changed' => $this->managedChanged,
                'uris' => $this->managedUris,
            ]);
        }

        $this->managedLoaded = TRUE;
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
        // Preload all managed file URIs.
        $this->loadManagedUris(TRUE);
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

            $uri = 'public://' . $relative_path;

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
    public function scanWithLists(int $limit = 5000) {
        $results = ['files' => 0, 'orphans' => 0, 'to_manage' => []];
        $patterns = $this->getIgnorePatterns();
        // Preload managed URIs for quick checks.
        $this->loadManagedUris(TRUE);
        $public_realpath = $this->fileSystem->realpath('public://');

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

            $uri = 'public://' . $relative_path;

            if (!isset($this->managedUris[$uri])) {
                $results['orphans']++;
                if (count($results['to_manage']) < $limit) {
                    $results['to_manage'][] = $uri;
                }
            }
        }

        return $results;
    }

    /**
     * Scans a portion of the public files directory using a resume token.
     *
     * @param string $resume
     *   Relative file path to resume from. Pass an empty string to start from
     *   the beginning.
     * @param int $limit
     *   Maximum number of file URIs to gather in this chunk.
     * @param int $timeLimit
     *   Time limit in seconds before the scan yields control.
     *
     * @return array
     *   Associative array with keys 'files', 'orphans', 'to_manage' and 'resume'.
     *   The 'resume' value will be empty when the scan is complete.
     */
    public function scanChunk(string $resume = '', int $limit = 5000, int $timeLimit = 10): array {
        $results = [
            'files' => 0,
            'orphans' => 0,
            'to_manage' => [],
            'resume' => '',
            'last_path' => '',
            'errors' => [],
        ];
        $start = microtime(TRUE);
        $patterns = $this->getIgnorePatterns();
        $this->loadManagedUris($resume === '');

        $public_realpath = $this->fileSystem->realpath('public://');
        if (!$public_realpath || !is_dir($public_realpath)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS)
        );

        $skipping = $resume !== '';
        try {
            foreach ($iterator as $file_info) {
                $relative_path = str_replace('\\', '/', $iterator->getSubPathname());
                $results['last_path'] = $relative_path;

            if ($timeLimit > 0 && microtime(TRUE) - $start >= $timeLimit) {
                $results['resume'] = $relative_path;
                break;
            }

            if ($skipping) {
                if ($relative_path === $resume) {
                    $skipping = FALSE;
                } else {
                    continue;
                }
            }

            if ($limit > 0 && count($results['to_manage']) >= $limit) {
                $results['resume'] = $relative_path;
                break;
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
                    break;
                }
            }
            if ($ignored) {
                continue;
            }

            $results['files']++;

            $uri = 'public://' . $relative_path;
            if (!isset($this->managedUris[$uri])) {
                $results['orphans']++;
                if (count($results['to_manage']) < $limit) {
                    $results['to_manage'][] = $uri;
                }
            }
        }
        }
        catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        // Persist managed URI list for the next batch.
        $this->state->set(self::STATE_KEY, [
            'changed' => $this->managedChanged,
            'uris' => $this->managedUris,
        ]);

        return $results;
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
     * Counts files for each directory in a single traversal.
     *
     * The returned array maps a relative directory path to the number of files
     * beneath that directory (recursively) that do not match ignore patterns.
     * The root directory is represented by an empty string key.
     *
     * @param string $relative_path
     *   (optional) Directory to start scanning from, relative to the public
     *   files directory. Defaults to the root directory.
     *
     * @return array
     *   Associative array of directory paths and counts.
     */
    public function countFilesByDirectory(string $relative_path = ''): array {
        $patterns = $this->getIgnorePatterns();
        $public_realpath = $this->fileSystem->realpath('public://');

        $counts = [];

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $counts;
        }

        $relative_path = trim($relative_path, '/');
        $base = $relative_path === '' ? $public_realpath : $public_realpath . DIRECTORY_SEPARATOR . $relative_path;

        if (!is_dir($base)) {
            return $counts;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
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

            $dir = dirname($relative);
            if ($dir === '.') {
                $dir = '';
            }

            while (TRUE) {
                if (!isset($counts[$dir])) {
                    $counts[$dir] = 0;
                }
                $counts[$dir]++;

                if ($dir === '') {
                    break;
                }
                $dir = dirname($dir);
                if ($dir === '.') {
                    $dir = '';
                }
            }
        }

        return $counts;
    }

    /**
     * Adopts (registers) the given files as managed file entities.
     *
     * @param string[] $file_uris
     *   Array of file URIs (public://...) to adopt.
     *
     * @return array
     *   An associative array with the keys:
     *   - count: The number of successfully adopted files.
     *   - errors: A list of error message strings for files that failed to be
     *     adopted.
     */
    public function adoptFiles(array $file_uris): array {
        $this->loadManagedUris();
        $count = 0;
        $errors = [];
        foreach ($file_uris as $uri) {
            if ($this->adoptFile($uri)) {
                $count++;
                $this->managedUris[$uri] = TRUE;
            }
            else {
                $errors[] = "Failed to adopt file {$uri}.";
            }
        }
        if ($count > 0) {
            $this->managedChanged = time();
            $this->state->set(self::STATE_KEY, [
                'changed' => $this->managedChanged,
                'uris' => $this->managedUris,
            ]);
        }

        return ['count' => $count, 'errors' => $errors];
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
    public function adoptFile(string $uri): bool {

        try {
            if (!$this->managedLoaded) {
                $this->loadManagedUris();
            }

            if ($this->isManaged($uri)) {
                return FALSE;
            }

            $file = File::create([
                'uri' => $uri,
                'filename' => basename($uri),
                'status' => 1,
                'uid' => 0,
            ]);
            $file->save();

            $this->managedUris[$uri] = TRUE;

            $this->managedChanged = time();
            $this->state->set(self::STATE_KEY, [
                'changed' => $this->managedChanged,
                'uris' => $this->managedUris,
            ]);

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
        if ($this->managedLoaded) {
            return isset($this->managedUris[$uri]);
        }
        $query = $this->database->select('file_managed', 'fm')
            ->fields('fm', ['fid'])
            ->condition('uri', $uri)
            ->range(0, 1);
        return (bool) $query->execute()->fetchField();
    }
}
