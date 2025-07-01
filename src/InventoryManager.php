<?php

namespace Drupal\file_adoption;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Provides database-driven operations for file adoption.
 */
class InventoryManager {

    protected Connection $database;
    protected FileSystemInterface $fileSystem;
    protected FileScanner $scanner;

    public function __construct(Connection $database, FileSystemInterface $file_system, FileScanner $scanner) {
        $this->database = $database;
        $this->fileSystem = $file_system;
        $this->scanner = $scanner;
    }

    /**
     * Check if the injected database supports queries.
     */
    protected function hasDb(): bool {
        return is_object($this->database) && method_exists($this->database, 'select');
    }

    /**
     * Returns a list of file URIs from the tracking table.
     */
    public function listFiles(bool $ignored = FALSE, bool $unmanaged = FALSE, int $limit = 50): array {
        if (!$this->hasDb()) {
            return [];
        }
        try {
            $query = $this->database->select('file_adoption_file', 'f')
                ->fields('f', ['uri'])
                ->orderBy('uri')
                ->range(0, $limit);
            if ($ignored) {
                $query->condition('f.ignore', 1);
            }
            if ($unmanaged) {
                $query->condition('f.managed', 0);
            }
            $result = $query->execute();
            $items = [];
            foreach ($result as $row) {
                $items[] = $row->uri;
            }
            return $items;
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Counts files in the tracking table.
     */
    public function countFiles(bool $ignored = FALSE, bool $unmanaged = FALSE): int {
        if (!$this->hasDb()) {
            return 0;
        }
        try {
            $query = $this->database->select('file_adoption_file', 'f');
            if ($ignored) {
                $query->condition('f.ignore', 1);
            }
            if ($unmanaged) {
                $query->condition('f.managed', 0);
            }
            $query->addExpression('COUNT(*)');
            return (int) $query->execute()->fetchField();
        }
        catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Returns a list of directory URIs from the tracking table.
     */
    public function listDirs(bool $ignored = FALSE, int $limit = 50): array {
        if (!$this->hasDb()) {
            return [];
        }
        try {
            $query = $this->database->select('file_adoption_dir', 'd')
                ->fields('d', ['uri'])
                ->orderBy('uri')
                ->range(0, $limit);
            if ($ignored) {
                $query->condition('d.ignore', 1);
            }
            $result = $query->execute();
            $items = [];
            foreach ($result as $row) {
                $items[] = $row->uri;
            }
            return $items;
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Counts directories in the tracking table.
     */
    public function countDirs(bool $ignored = FALSE): int {
        if (!$this->hasDb()) {
            return 0;
        }
        try {
            $query = $this->database->select('file_adoption_dir', 'd');
            if ($ignored) {
                $query->condition('d.ignore', 1);
            }
            $query->addExpression('COUNT(*)');
            return (int) $query->execute()->fetchField();
        }
        catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Returns all directories grouped by ignore status.
     *
     * @return array{
     *   active: string[],
     *   ignored: string[],
     * }
     *   Arrays of directory URIs keyed by ignore flag.
     */
    public function listDirsGrouped(): array {
        $groups = ['active' => [], 'ignored' => []];
        if (!$this->hasDb()) {
            return $groups;
        }
        try {
            $query = $this->database->select('file_adoption_dir', 'd')
                ->fields('d', ['uri', 'ignore'])
                ->orderBy('uri');
            $result = $query->execute();
            foreach ($result as $row) {
                if ($row->ignore) {
                    $groups['ignored'][] = $row->uri;
                }
                else {
                    $groups['active'][] = $row->uri;
                }
            }
        }
        catch (\Throwable $e) {
            // Ignore errors and return empty arrays.
        }
        return $groups;
    }

    /**
     * Returns directory summaries including an example file and file count.
     *
     * @param int $limit
     *   Maximum number of directories to return.
     *
     * @return array[]
     *   Array of associative arrays with keys: uri, ignored, count, example.
     */
    public function listDirSummaries(int $limit = 20): array {
        $summary = [];
        if (!$this->hasDb()) {
            return $summary;
        }
        try {
            $query = $this->database->select('file_adoption_dir', 'd')
                ->fields('d', ['id', 'uri', 'ignore'])
                ->orderBy('d.uri')
                ->range(0, $limit);
            $query->leftJoin('file_adoption_file', 'f', 'f.parent_dir = d.id AND f.ignore = 0');
            $query->addExpression('COUNT(f.id)', 'file_count');
            $query->addExpression('MIN(f.uri)', 'first_file');
            $query->groupBy('d.id');
            $result = $query->execute();
            foreach ($result as $row) {
                $example = $row->first_file ? basename($row->first_file) : NULL;
                $summary[] = [
                    'uri' => $row->uri,
                    'ignored' => (bool) $row->ignore,
                    'count' => (int) $row->file_count,
                    'example' => $example,
                ];
            }
        }
        catch (\Throwable $e) {
            // Ignore errors and return empty summary.
        }
        return $summary;
    }

    /**
     * Returns a list of unmanaged files ordered by id.
     */
    public function listUnmanagedById(int $limit): array {
        if (!$this->hasDb()) {
            return [];
        }
        try {
            $query = $this->database->select('file_adoption_file', 'f')
                ->fields('f', ['uri'])
                ->condition('f.ignore', 0)
                ->condition('f.managed', 0)
                ->orderBy('f.id')
                ->range(0, $limit);
            $result = $query->execute();
            $items = [];
            foreach ($result as $row) {
                $items[] = $row->uri;
            }
            return $items;
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Batch operation for adopting unmanaged files.
     */
    public static function batchAdopt(int $limit, array &$context): void {
        /** @var self $service */
        $service = \Drupal::service('file_adoption.inventory_manager');
        $service->runBatchAdopt($limit, $context);
    }

    protected function runBatchAdopt(int $limit, array &$context): void {
        if (!$this->hasDb()) {
            $context['finished'] = 1;
            return;
        }
        if (!isset($context['sandbox']['last_id'])) {
            $context['sandbox']['last_id'] = 0;
            $context['results']['adopted'] = 0;
        }
        $query = $this->database->select('file_adoption_file', 'f')
            ->fields('f', ['id', 'uri'])
            ->condition('f.ignore', 0)
            ->condition('f.managed', 0)
            ->condition('f.id', $context['sandbox']['last_id'], '>')
            ->orderBy('f.id')
            ->range(0, $limit);
        $result = $query->execute();
        $count = 0;
        foreach ($result as $row) {
            if ($this->scanner->adoptFile($row->uri)) {
                $count++;
            }
            $context['sandbox']['last_id'] = $row->id;
        }
        $context['results']['adopted'] += $count;
        if ($count < $limit) {
            $context['finished'] = 1;
        }
    }

    public static function adoptFinished(bool $success, array $results, array $operations): void {
        if ($success && !empty($results['adopted'])) {
            \Drupal::messenger()->addStatus(\Drupal::translation()->translate('@count file(s) adopted.', ['@count' => $results['adopted']]));
        }
    }

    /**
     * Batch operation to remove missing directory and file records.
     */
    public static function batchPurge(array &$context): void {
        /** @var self $service */
        $service = \Drupal::service('file_adoption.inventory_manager');
        if (!isset($context['results']['dirs'])) {
            $context['results']['dirs'] = $service->cleanupDirectories();
            $context['results']['files'] = $service->cleanupFiles();
            $context['finished'] = 1;
        }
    }

    /**
     * Displays cleanup results.
     */
    public static function purgeFinished(bool $success, array $results, array $operations): void {
        if ($success) {
            if (!empty($results['dirs'])) {
                \Drupal::messenger()->addStatus(\Drupal::translation()->translate('@count directory record(s) removed.', ['@count' => $results['dirs']]));
            }
            if (!empty($results['files'])) {
                \Drupal::messenger()->addStatus(\Drupal::translation()->translate('@count file record(s) removed.', ['@count' => $results['files']]));
            }
        }
    }

    /**
     * Removes directory records for paths that no longer exist.
     */
    public function cleanupDirectories(): int {
        if (!$this->hasDb()) {
            return 0;
        }
        $query = $this->database->select('file_adoption_dir', 'd')
            ->fields('d', ['id', 'uri']);
        $result = $query->execute();
        $removed = 0;
        foreach ($result as $row) {
            $real = $this->fileSystem->realpath($row->uri);
            if (!$real || !is_dir($real)) {
                $this->database->delete('file_adoption_dir')
                    ->condition('id', $row->id)
                    ->execute();
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Removes file records for URIs that no longer exist.
     */
    public function cleanupFiles(): int {
        if (!$this->hasDb()) {
            return 0;
        }
        $query = $this->database->select('file_adoption_file', 'f')
            ->fields('f', ['id', 'uri']);
        $result = $query->execute();
        $removed = 0;
        foreach ($result as $row) {
            $real = $this->fileSystem->realpath($row->uri);
            if (!$real || !file_exists($real)) {
                $this->database->delete('file_adoption_file')
                    ->condition('id', $row->id)
                    ->execute();
                $removed++;
            }
        }
        return $removed;
    }

}
