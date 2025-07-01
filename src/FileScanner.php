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
     * Checks if the injected database connection supports query methods.
     */
    protected function hasDb(): bool {
        return is_object($this->database) && method_exists($this->database, 'select');
    }

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
    public function getIgnorePatterns(): array {
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
     * Loads all managed file URIs into the local cache.
     */
    protected function loadManagedUris(): void {
        $this->managedUris = [];
        $this->managedLoaded = TRUE;

        if ($this->hasDb()) {
            try {
                $query = $this->database->select('file_adoption_file', 'faf')
                    ->fields('faf', ['uri'])
                    ->condition('managed', 1);
                $result = $query->execute();
                foreach ($result as $record) {
                    $this->managedUris[$record->uri] = TRUE;
                }
                return;
            }
            catch (\Throwable $e) {
                // Fall back to file_managed table below.
            }
        }

        $result = $this->database->select('file_managed', 'fm')
            ->fields('fm', ['uri'])
            ->execute();
        foreach ($result as $record) {
            $this->managedUris[$record->uri] = TRUE;
        }
    }

    /**
     * Returns the number of entries in the file_managed table.
     *
     * @return int
     *   The count of managed files.
     */
    public function countManagedFiles(): int {
        if ($this->hasDb()) {
            try {
                $query = $this->database->select('file_adoption_file', 'faf')
                    ->condition('managed', 1);
                $query->addExpression('COUNT(*)');
                return (int) $query->execute()->fetchField();
            }
            catch (\Throwable $e) {
                // Fallback below
            }
        }

        $query = $this->database->select('file_managed', 'fm');
        $query->addExpression('COUNT(*)');
        return (int) $query->execute()->fetchField();
    }

    /**
     * Inserts or updates a directory record and returns its ID.
     */
    protected function ensureDirectory(string $uri, int $modified): int {
        if (!$this->hasDb()) {
            return 0;
        }
        try {
            $this->database->merge('file_adoption_dir')
                ->key(['uri' => $uri])
                ->fields(['modified' => $modified])
                ->execute();
            $query = $this->database->select('file_adoption_dir', 'd')
                ->fields('d', ['id'])
                ->condition('uri', $uri)
                ->range(0, 1);
            return (int) $query->execute()->fetchField();
        }
        catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Inserts or updates a file record linked to a directory.
     */
    protected function ensureFile(string $uri, int $modified, int $dir_id): void {
        if (!$this->hasDb()) {
            return;
        }
        try {
            $this->database->merge('file_adoption_file')
                ->key(['uri' => $uri])
                ->fields([
                    'modified' => $modified,
                    'parent_dir' => $dir_id,
                ])
                ->execute();
        }
        catch (\Throwable $e) {
            // Ignore errors when updating tracking data.
        }
    }

    /**
     * Marks a directory or file as ignored.
     */
    protected function markIgnored(string $uri, bool $directory = FALSE): void {
        if (!$this->hasDb()) {
            return;
        }
        $table = $directory ? 'file_adoption_dir' : 'file_adoption_file';
        try {
            $this->database->update($table)
                ->fields(['ignore' => 1])
                ->condition('uri', $uri)
                ->execute();
        }
        catch (\Throwable $e) {
        }
    }

    /**
     * Marks a file record as managed.
     */
    protected function markManaged(string $uri): void {
        if (!$this->hasDb()) {
            return;
        }
        try {
            $this->database->update('file_adoption_file')
                ->fields(['managed' => 1])
                ->condition('uri', $uri)
                ->execute();
        }
        catch (\Throwable $e) {
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
    public function scanAndProcess(bool $adopt = TRUE, int $limit = 0): array {
        $counts = ['files' => 0, 'orphans' => 0, 'adopted' => 0, 'errors' => 0];
        $patterns = $this->getIgnorePatterns();
        $follow_symlinks = (bool) $this->configFactory->get('file_adoption.settings')->get('follow_symlinks');
        // Preload managed URIs.
        $this->loadManagedUris();

        $known_dirs = [];
        $ignored_dirs = [];
        $known_files = [];
        $ignored_files = [];
        if ($this->hasDb()) {
            try {
                $res = $this->database->select('file_adoption_dir', 'd')
                    ->fields('d', ['id', 'uri', 'modified', 'ignore'])
                    ->execute();
                foreach ($res as $r) {
                    $rel = str_replace('public://', '', $r->uri);
                    $known_dirs[$rel] = ['id' => $r->id, 'modified' => $r->modified];
                    if ($r->ignore) {
                        $ignored_dirs[$rel] = TRUE;
                    }
                }
                $res = $this->database->select('file_adoption_file', 'f')
                    ->fields('f', ['uri', 'modified', 'ignore', 'managed', 'parent_dir'])
                    ->execute();
                foreach ($res as $r) {
                    $known_files[$r->uri] = [
                        'modified' => $r->modified,
                        'ignore' => $r->ignore,
                        'managed' => $r->managed,
                        'parent_dir' => $r->parent_dir,
                    ];
                    if ($r->ignore) {
                        $ignored_files[$r->uri] = TRUE;
                    }
                    if ($r->managed) {
                        $this->managedUris[$r->uri] = TRUE;
                    }
                }
            }
            catch (\Throwable $e) {
            }
        }
        // Only track whether the file is already managed.
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $counts;
        }

        $flags = \FilesystemIterator::SKIP_DOTS;
        if ($follow_symlinks) {
            $flags |= \RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        }

        try {
            $directory = new \RecursiveDirectoryIterator($public_realpath, $flags);
            if ($follow_symlinks) {
                $visited = [$public_realpath => TRUE];
                $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use (&$visited) {
                    if ($current->isDir()) {
                        $real = $current->getRealPath();
                        if ($real === FALSE || isset($visited[$real])) {
                            return FALSE;
                        }
                        $visited[$real] = TRUE;
                    }
                    return TRUE;
                });
                $iterator = new \RecursiveIteratorIterator($filter);
            }
            else {
                $iterator = new \RecursiveIteratorIterator($directory);
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $counts['errors']++;
            return $counts;
        }
        catch (\Throwable $e) {
            $this->logger->error('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $counts['errors']++;
            return $counts;
        }

        try {
            foreach ($iterator as $file_info) {
                if ($adopt && $limit > 0 && $counts['adopted'] >= $limit) {
                    break;
                }
                if (!$file_info->isFile()) {
                    continue;
                }
                if (!$follow_symlinks && $file_info->isLink()) {
                    continue;
                }

                $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

                foreach ($ignored_dirs as $dir => $v) {
                    if ($relative_path === $dir || str_starts_with($relative_path, $dir . '/')) {
                        continue 2;
                    }
                }

                if (isset($ignored_files['public://' . $relative_path])) {
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
                        break;
                    }
                }
                if ($ignored) {
                    continue;
                }

                $counts['files']++;

                $uri = 'public://' . $relative_path;

                $mtime = $file_info->getMTime();

                if (isset($known_files[$uri]) && $known_files[$uri]['modified'] == $mtime) {
                    if ($known_files[$uri]['managed']) {
                        continue;
                    }
                }

                if (isset($this->managedUris[$uri])) {
                    continue;
                }

                $dir_rel = dirname($relative_path);
                $dir_uri = $dir_rel === '.' ? 'public://' : 'public://' . $dir_rel;
                $dir_id = $this->ensureDirectory($dir_uri, $file_info->getMTime());
                $this->ensureFile($uri, $mtime, $dir_id);

                $counts['orphans']++;

                if ($adopt) {
                    try {
                        if ($this->adoptFile($uri)) {
                            $counts['adopted']++;
                            $this->managedUris[$uri] = TRUE;
                        }
                    }
                    catch (\UnexpectedValueException | \RuntimeException $e) {
                        $this->logger->warning('Failed processing file @file: @message', [
                            '@file' => $uri,
                            '@message' => $e->getMessage(),
                        ]);
                        $counts['errors']++;
                    }
                    catch (\Throwable $e) {
                        $this->logger->error('Failed processing file @file: @message', [
                            '@file' => $uri,
                            '@message' => $e->getMessage(),
                        ]);
                        $counts['errors']++;
                    }
                }
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $counts['errors']++;
        }
        catch (\Throwable $e) {
            $this->logger->error('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $counts['errors']++;
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
    public function scanWithLists(int $limit = 500): array {
        $results = ['files' => 0, 'orphans' => 0, 'to_manage' => [], 'errors' => 0];
        $patterns = $this->getIgnorePatterns();
        $follow_symlinks = (bool) $this->configFactory->get('file_adoption.settings')->get('follow_symlinks');
        // Preload managed URIs for quick checks.
        $this->loadManagedUris();

        $known_dirs = [];
        $ignored_dirs = [];
        $known_files = [];
        $ignored_files = [];
        if ($this->hasDb()) {
            try {
                $res = $this->database->select('file_adoption_dir', 'd')
                    ->fields('d', ['id', 'uri', 'modified', 'ignore'])
                    ->execute();
                foreach ($res as $r) {
                    $rel = str_replace('public://', '', $r->uri);
                    $known_dirs[$rel] = ['id' => $r->id, 'modified' => $r->modified];
                    if ($r->ignore) {
                        $ignored_dirs[$rel] = TRUE;
                    }
                }
                $res = $this->database->select('file_adoption_file', 'f')
                    ->fields('f', ['uri', 'modified', 'ignore', 'managed', 'parent_dir'])
                    ->execute();
                foreach ($res as $r) {
                    $known_files[$r->uri] = [
                        'modified' => $r->modified,
                        'ignore' => $r->ignore,
                        'managed' => $r->managed,
                        'parent_dir' => $r->parent_dir,
                    ];
                    if ($r->ignore) {
                        $ignored_files[$r->uri] = TRUE;
                    }
                    if ($r->managed) {
                        $this->managedUris[$r->uri] = TRUE;
                    }
                }
            }
            catch (\Throwable $e) {
            }
        }
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $results;
        }

        $flags = \FilesystemIterator::SKIP_DOTS;
        if ($follow_symlinks) {
            $flags |= \RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        }

        try {
            $directory = new \RecursiveDirectoryIterator($public_realpath, $flags);
            if ($follow_symlinks) {
                $visited = [$public_realpath => TRUE];
                $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use (&$visited) {
                    if ($current->isDir()) {
                        $real = $current->getRealPath();
                        if ($real === FALSE || isset($visited[$real])) {
                            return FALSE;
                        }
                        $visited[$real] = TRUE;
                    }
                    return TRUE;
                });
                $iterator = new \RecursiveIteratorIterator($filter);
            }
            else {
                $iterator = new \RecursiveIteratorIterator($directory);
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
            return $results;
        }
        catch (\Throwable $e) {
            $this->logger->error('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
            return $results;
        }

        try {
            foreach ($iterator as $file_info) {
                if (!$file_info->isFile()) {
                    continue;
                }
                if (!$follow_symlinks && $file_info->isLink()) {
                    continue;
                }

                $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

                if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
                    continue;
                }

                foreach ($ignored_dirs as $dir => $v) {
                    if ($relative_path === $dir || str_starts_with($relative_path, $dir . '/')) {
                        continue 2;
                    }
                }

                if (isset($ignored_files['public://' . $relative_path])) {
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

                $mtime = $file_info->getMTime();

                if (isset($known_files[$uri]) && $known_files[$uri]['modified'] == $mtime) {
                    if ($known_files[$uri]['managed']) {
                        continue;
                    }
                }

                if (!isset($this->managedUris[$uri])) {
                    $dir_rel = dirname($relative_path);
                    $dir_uri = $dir_rel === '.' ? 'public://' : 'public://' . $dir_rel;
                    $dir_id = $this->ensureDirectory($dir_uri, $file_info->getMTime());
                    $this->ensureFile($uri, $mtime, $dir_id);

                    $results['orphans']++;
                    if (count($results['to_manage']) < $limit) {
                        $results['to_manage'][] = $uri;
                    }
                }
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
        }
        catch (\Throwable $e) {
            $this->logger->error('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Scans a subset of files starting at an offset.
     *
     * @param int $offset
     *   Number of matching files to skip before starting.
     * @param int $limit
     *   Maximum number of files to return.
     *
     * @return array
     *   Associative array with keys 'results' and 'offset'.
     */
    public function scanChunk(int $offset, int $limit = 100): array {
        $chunk = ['results' => ['files' => 0, 'orphans' => 0, 'to_manage' => [], 'errors' => 0], 'offset' => $offset];

        $patterns = $this->getIgnorePatterns();
        $follow_symlinks = (bool) $this->configFactory->get('file_adoption.settings')->get('follow_symlinks');
        $this->loadManagedUris();

        $known_dirs = [];
        $ignored_dirs = [];
        $known_files = [];
        $ignored_files = [];
        if ($this->hasDb()) {
            try {
                $res = $this->database->select('file_adoption_dir', 'd')
                    ->fields('d', ['id', 'uri', 'modified', 'ignore'])
                    ->execute();
                foreach ($res as $r) {
                    $rel = str_replace('public://', '', $r->uri);
                    $known_dirs[$rel] = ['id' => $r->id, 'modified' => $r->modified];
                    if ($r->ignore) {
                        $ignored_dirs[$rel] = TRUE;
                    }
                }
                $res = $this->database->select('file_adoption_file', 'f')
                    ->fields('f', ['uri', 'modified', 'ignore', 'managed', 'parent_dir'])
                    ->execute();
                foreach ($res as $r) {
                    $known_files[$r->uri] = [
                        'modified' => $r->modified,
                        'ignore' => $r->ignore,
                        'managed' => $r->managed,
                        'parent_dir' => $r->parent_dir,
                    ];
                    if ($r->ignore) {
                        $ignored_files[$r->uri] = TRUE;
                    }
                    if ($r->managed) {
                        $this->managedUris[$r->uri] = TRUE;
                    }
                }
            }
            catch (\Throwable $e) {
            }
        }
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $chunk;
        }

        $flags = \FilesystemIterator::SKIP_DOTS;
        if ($follow_symlinks) {
            $flags |= \RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        }

        try {
            $directory = new \RecursiveDirectoryIterator($public_realpath, $flags);
            if ($follow_symlinks) {
                $visited = [$public_realpath => TRUE];
                $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use (&$visited) {
                    if ($current->isDir()) {
                        $real = $current->getRealPath();
                        if ($real === FALSE || isset($visited[$real])) {
                            return FALSE;
                        }
                        $visited[$real] = TRUE;
                    }
                    return TRUE;
                });
                $iterator = new \RecursiveIteratorIterator($filter);
            }
            else {
                $iterator = new \RecursiveIteratorIterator($directory);
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $chunk['results']['errors']++;
            return $chunk;
        }
        catch (\Throwable $e) {
            $this->logger->error('Failed to iterate directory @dir: @message', [
                '@dir' => $public_realpath,
                '@message' => $e->getMessage(),
            ]);
            $chunk['results']['errors']++;
            return $chunk;
        }

        $index = 0;
        try {
            foreach ($iterator as $file_info) {
                if (!$file_info->isFile()) {
                    continue;
                }
                if (!$follow_symlinks && $file_info->isLink()) {
                    continue;
                }

                $relative_path = str_replace('\\', '/', $iterator->getSubPathname());

                if (preg_match('/(^|\/)(\.|\.{2})/', $relative_path)) {
                    continue;
                }

                foreach ($ignored_dirs as $dir => $v) {
                    if ($relative_path === $dir || str_starts_with($relative_path, $dir . '/')) {
                        continue 2;
                    }
                }

                if (isset($ignored_files['public://' . $relative_path])) {
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

                if ($index < $offset) {
                    $index++;
                    continue;
                }

                if ($chunk['results']['files'] >= $limit) {
                    break;
                }

                $index++;
                $chunk['offset'] = $index;
                $chunk['results']['files']++;

                $uri = 'public://' . $relative_path;
                $mtime = $file_info->getMTime();

                if (isset($known_files[$uri]) && $known_files[$uri]['modified'] == $mtime) {
                    if ($known_files[$uri]['managed']) {
                        continue;
                    }
                }

                if (!isset($this->managedUris[$uri])) {
                    $dir_rel = dirname($relative_path);
                    $dir_uri = $dir_rel === '.' ? 'public://' : 'public://' . $dir_rel;
                    $dir_id = $this->ensureDirectory($dir_uri, $file_info->getMTime());
                    $this->ensureFile($uri, $mtime, $dir_id);

                    $chunk['results']['orphans']++;
                    $chunk['results']['to_manage'][] = $uri;
                }
            }
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $chunk['results']['errors']++;
        }
        catch (\Throwable $e) {
            $this->logger->error('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
            $chunk['results']['errors']++;
        }

        return $chunk;
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
        $follow_symlinks = (bool) $this->configFactory->get('file_adoption.settings')->get('follow_symlinks');
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return 0;
        }

        $relative_path = trim($relative_path, '/');
        $base = $relative_path === '' ? $public_realpath : $public_realpath . DIRECTORY_SEPARATOR . $relative_path;
        if (!is_dir($base)) {
            return 0;
        }

        $flags = \FilesystemIterator::SKIP_DOTS;
        if ($follow_symlinks) {
            $flags |= \RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        }

        try {
            $directory = new \RecursiveDirectoryIterator($base, $flags);
            if ($follow_symlinks) {
                $visited = [$base => TRUE];
                $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use (&$visited) {
                    if ($current->isDir()) {
                        $real = $current->getRealPath();
                        if ($real === FALSE || isset($visited[$real])) {
                            return FALSE;
                        }
                        $visited[$real] = TRUE;
                    }
                    return TRUE;
                });
                $iterator = new \RecursiveIteratorIterator($filter);
            }
            else {
                $iterator = new \RecursiveIteratorIterator($directory);
            }
        }
        catch (\Throwable $e) {
            $this->logger->error('Failed to iterate directory @dir: @message', [
                '@dir' => $base,
                '@message' => $e->getMessage(),
            ]);
            return 0;
        }

        $count = 0;
        try {
            foreach ($iterator as $file_info) {
                if (!$file_info->isFile()) {
                    continue;
                }
                if (!$follow_symlinks && $file_info->isLink()) {
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
        }
        catch (\Throwable $e) {
            $this->logger->error('Directory iteration error: @message', [
                '@message' => $e->getMessage(),
            ]);
        }

        return $count;
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
    public function adoptFiles(array $file_uris): int {
        $this->loadManagedUris();
        $count = 0;
        foreach ($file_uris as $uri) {
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

            if ($this->hasDb()) {
                try {
                    $this->database->merge('file_adoption_file')
                        ->key(['uri' => $uri])
                        ->fields(['managed' => 1])
                        ->execute();
                    $this->markManaged($uri);
                }
                catch (\Throwable $e) {
                    // Ignore database update errors when adopting.
                }
            }

            $this->managedUris[$uri] = TRUE;

            $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
            return TRUE;
        }
        catch (\UnexpectedValueException | \RuntimeException $e) {
            $this->logger->warning('Failed to adopt file @file: @message', [
                '@file' => $uri,
                '@message' => $e->getMessage(),
            ]);
            return FALSE;
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
        if ($this->hasDb()) {
            try {
                $query = $this->database->select('file_adoption_file', 'faf')
                    ->fields('faf', ['id'])
                    ->condition('uri', $uri)
                    ->condition('managed', 1)
                    ->range(0, 1);
                return (bool) $query->execute()->fetchField();
            }
            catch (\Throwable $e) {
                // Fallback below
            }
        }

        $query = $this->database->select('file_managed', 'fm')
            ->fields('fm', ['fid'])
            ->condition('uri', $uri)
            ->range(0, 1);
        return (bool) $query->execute()->fetchField();
    }
}
