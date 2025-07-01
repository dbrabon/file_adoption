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
     * Batch operation for cleaning stale tracking data.
     */
    public static function batchCleanup(int $limit, array &$context): void {
        /** @var self $service */
        $service = \Drupal::service('file_adoption.inventory_manager');
        $service->runBatchCleanup($limit, $context);
    }

    protected function runBatchCleanup(int $limit, array &$context): void {
        if (!$this->hasDb()) {
            $context['finished'] = 1;
            return;
        }
        if (!isset($context['sandbox']['last_id'])) {
            $context['sandbox']['last_id'] = 0;
            $context['results']['removed'] = 0;
        }
        $query = $this->database->select('file_adoption_file', 'f')
            ->fields('f', ['id', 'uri'])
            ->condition('f.id', $context['sandbox']['last_id'], '>')
            ->orderBy('f.id')
            ->range(0, $limit);
        $result = $query->execute();
        $processed = 0;
        foreach ($result as $row) {
            $real = $this->fileSystem->realpath($row->uri);
            if (!$real || !file_exists($real)) {
                $this->database->delete('file_adoption_file')
                    ->condition('id', $row->id)
                    ->execute();
                $context['results']['removed']++;
            }
            $context['sandbox']['last_id'] = $row->id;
            $processed++;
        }
        if ($processed < $limit) {
            $context['finished'] = 1;
        }
    }

    public static function cleanupFinished(bool $success, array $results, array $operations): void {
        if ($success && !empty($results['removed'])) {
            \Drupal::messenger()->addStatus(\Drupal::translation()->translate('@count stale record(s) removed.', ['@count' => $results['removed']]));
        }
    }
}
