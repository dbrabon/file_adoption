<?php

namespace Drupal\file_adoption;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\Entity\File;

/**
 * Service for scanning node content for hardlinked files.
 */
class HardLinkScanner {

    /**
     * Database connection.
     *
     * @var \Drupal\Core\Database\Connection
     */
    protected $database;

    /**
     * Logger channel.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * File usage tracking service.
     *
     * @var \Drupal\file\FileUsage\FileUsageInterface
     */
    protected $fileUsage;

    /**
     * SQL LIKE pattern used to narrow matches.
     *
     * @var string
     */
    protected $pattern = '%/sites/%/files/%';

    /**
     * Constructs a new HardLinkScanner.
     */
    public function __construct(Connection $database, LoggerInterface $logger, FileUsageInterface $fileUsage) {
        $this->database = $database;
        $this->logger = $logger;
        $this->fileUsage = $fileUsage;
    }

    /**
     * Normalizes file URIs using the public:// scheme.
     */
    protected function canonicalizeUri(string $uri): string {
        $uri = preg_split('/[#?]/', $uri, 2)[0];
        if (str_starts_with($uri, 'public://')) {
            return 'public://' . ltrim(substr($uri, 9), '/');
        }
        if (preg_match('#/sites/[^/]+/files/(.+)$#', $uri, $matches)) {
            return 'public://' . $matches[1];
        }
        return $uri;
    }

    /**
     * Refreshes the file_adoption_hardlinks table.
     */
    public function refresh(): void {
        $schema = $this->database->schema();
        $tables = $schema->findTables('node\_%');

        // Clear existing data.
        $this->database->truncate('file_adoption_hardlinks')->execute();

        foreach ($tables as $table) {
            $fields = $schema->fieldNames($table);
            foreach ($fields as $field) {
                if (!str_ends_with($field, '_value')) {
                    continue;
                }
                $query = $this->database->select($table, 't');
                $query->fields('t', ['entity_id', $field]);
                $query->condition($field, $this->pattern, 'LIKE');
                $results = $query->execute();

                foreach ($results as $record) {
                    $matches = [];
                    preg_match_all('#(?:src|href)=(["\'])([^"\']+)\1#i', $record->$field, $matches);
                    foreach ($matches[2] as $uri) {
                        if (!str_contains($uri, '/files/')) {
                            continue;
                        }
                        $uri = $this->canonicalizeUri($uri);
                        $this->database->merge('file_adoption_hardlinks')
                            ->key(['uri' => $uri])
                            ->fields([
                                'nid' => $record->entity_id,
                                'timestamp' => time(),
                            ])
                            ->execute();
                    }
                }
            }
        }
    }

    /**
     * Adds file usage for managed files referenced in hard links.
     *
     * @return int
     *   Number of usage records added.
     */
    public function syncUsage(): int {
        $added = 0;
        $records = $this->database->select('file_adoption_hardlinks', 'h')
            ->fields('h', ['nid', 'uri'])
            ->execute();

        foreach ($records as $record) {
            $fid = $this->database->select('file_managed', 'fm')
                ->fields('fm', ['fid'])
                ->condition('uri', $record->uri)
                ->execute()
                ->fetchField();
            if (!$fid) {
                continue;
            }

            $file = File::load($fid);
            if (!$file) {
                continue;
            }

            $usage = $this->fileUsage->listUsage($file);
            if (empty($usage['file_adoption']['node'][$record->nid])) {
                $this->fileUsage->add($file, 'file_adoption', 'node', (int) $record->nid);
                $added++;
            }
        }

        return $added;
    }
}
