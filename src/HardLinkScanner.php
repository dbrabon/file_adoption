<?php

namespace Drupal\file_adoption;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

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
     * SQL LIKE pattern used to narrow matches.
     *
     * @var string
     */
    protected $pattern = '%/sites/%/files/%';

    /**
     * Constructs a new HardLinkScanner.
     */
    public function __construct(Connection $database, LoggerInterface $logger) {
        $this->database = $database;
        $this->logger = $logger;
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
     *
     * The scanner checks each field column ending with `_value` (text fields)
     * or `_uri` (link fields) for file references.
     */
    public function refresh(): void {
        $schema = $this->database->schema();
        $tables = $schema->findTables('node\_%');

        // Clear existing data.
        $this->database->truncate('file_adoption_hardlinks')->execute();

        foreach ($tables as $table) {
            $fields = $schema->fieldNames($table);
            foreach ($fields as $field) {
                // Only process field columns that store user entered values. In
                // core Drupal tables these typically end with `_value` for text
                // fields or `_uri` for link fields.
                if (!str_ends_with($field, '_value') && !str_ends_with($field, '_uri')) {
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
                            ->key([
                                'uri' => $uri,
                                'nid' => $record->entity_id,
                            ])
                            ->fields([
                                'timestamp' => time(),
                            ])
                            ->execute();
                    }
                }
            }
        }
    }
}
