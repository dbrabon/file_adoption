<?php

namespace Drupal\file_adoption;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
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
     * Cache backend for discovered fields.
     *
     * @var \Drupal\Core\Cache\CacheBackendInterface
     */
    protected $cache;

    /**
     * Cache ID used to store the field map.
     *
     * @var string
     */
    protected $cacheId = 'hardlink_text_fields';

    /**
     * SQL LIKE pattern used to narrow matches.
     *
     * @var string
     */
    protected $pattern = '%/sites/%/files/%';

    /**
     * Constructs a new HardLinkScanner.
     */
    public function __construct(Connection $database, LoggerInterface $logger, CacheBackendInterface $cache) {
        $this->database = $database;
        $this->logger = $logger;
        $this->cache = $cache;
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
     * Retrieves a map of tables to text-based fields.
     *
     * Results are cached in the file_adoption cache bin.
     */
    private function getTextFields(): array {
        $cached = $this->cache->get($this->cacheId);
        if ($cached) {
            return $cached->data;
        }

        $schema = $this->database->schema();
        $tables = $schema->findTables('%');
        $map = [];

        foreach ($tables as $table) {
            $columns = [];

            if (method_exists($schema, 'introspectSchema')) {
                $ref = new \ReflectionClass($schema);
                if ($ref->hasMethod('introspectSchema')) {
                    $method = $ref->getMethod('introspectSchema');
                    $method->setAccessible(TRUE);
                    try {
                        $info = $method->invoke($schema, $table);
                        foreach ($info['fields'] ?? [] as $name => $definition) {
                            $columns[$name] = $definition['type'] ?? '';
                        }
                    }
                    catch (\Throwable $e) {
                        $columns = [];
                    }
                }
            }

            if (!$columns) {
                try {
                    $result = $this->database->query("SELECT * FROM {" . $table . "} WHERE 1=0");
                    $count = $result->columnCount();
                    for ($i = 0; $i < $count; $i++) {
                        $meta = $result->getColumnMeta($i);
                        $name = $meta['name'] ?? NULL;
                        if ($name) {
                            $type = strtolower($meta['native_type'] ?? '');
                            $columns[$name] = $type;
                        }
                    }
                }
                catch (\Throwable $e) {
                    $columns = [];
                }
            }

            foreach ($columns as $field => $type) {
                if (method_exists($schema, 'fieldGetDefinition')) {
                    try {
                        $definition = $schema->fieldGetDefinition($table, $field);
                        $type = $definition['type'] ?? $type;
                    }
                    catch (\Throwable $e) {
                        // Fall back to type from metadata.
                    }
                }
                if (str_contains($type, 'char') || str_contains($type, 'text')) {
                    $map[$table][] = $field;
                }
            }
        }

        $this->cache->set($this->cacheId, $map);
        $this->logger->info('HardLinkScanner field map built: @map', ['@map' => var_export($map, TRUE)]);

        return $map;
    }

    /**
     * Refreshes the file_adoption_hardlinks table.
     *
     * The scanner inspects every table for text-based columns and scans those
     * fields for file references.
     */
    public function refresh(): void {
        $schema = $this->database->schema();
        $map = $this->getTextFields();

        // Clear existing data.
        $this->database->truncate('file_adoption_hardlinks')->execute();

        foreach ($map as $table => $fields) {
            if (!$schema->tableExists($table)) {
                continue;
            }

            $nid_field = NULL;
            $pk_fields = [];

            if (str_starts_with($table, 'node_')) {
                $nid_field = 'entity_id';
            }
            else {
                if (method_exists($schema, 'introspectSchema')) {
                    $ref = new \ReflectionClass($schema);
                    if ($ref->hasMethod('introspectSchema')) {
                        $method = $ref->getMethod('introspectSchema');
                        $method->setAccessible(TRUE);
                        try {
                            $info = $method->invoke($schema, $table);
                            $pk_fields = $info['primary key'] ?? [];
                        }
                        catch (\Throwable $e) {
                            $pk_fields = [];
                        }
                    }
                }
            }

            foreach ($fields as $field) {
                $select_fields = array_merge($pk_fields, [$field]);
                if ($nid_field !== NULL && !in_array($nid_field, $select_fields)) {
                    array_unshift($select_fields, $nid_field);
                }

                $query = $this->database->select($table, 't');
                $query->fields('t', $select_fields);
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
                        if ($nid_field !== NULL) {
                            $this->database->merge('file_adoption_hardlinks')
                                ->key([
                                    'nid' => $record->$nid_field,
                                    'uri' => $uri,
                                ])
                                ->fields([
                                    'table_name' => NULL,
                                    'row_id' => NULL,
                                    'timestamp' => time(),
                                ])
                                ->execute();
                        }
                        elseif ($pk_fields) {
                            $row_id = [];
                            foreach ($pk_fields as $pk) {
                                $row_id[] = $record->$pk;
                            }
                            $row_id = implode(':', $row_id);
                            $this->database->merge('file_adoption_hardlinks')
                                ->key([
                                    'table_name' => $table,
                                    'row_id' => (string) $row_id,
                                    'uri' => $uri,
                                ])
                                ->fields([
                                    'nid' => NULL,
                                    'timestamp' => time(),
                                ])
                                ->execute();
                        }
                    }
                }
            }
        }
    }
}
