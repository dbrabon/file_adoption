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
     * The logger channel.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

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
     *   Logger channel for this service.
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
     * Scans the public files directory for orphaned files.
     *
     * @return string[]
     *   An array of URIs for files in public:// that are not managed by Drupal.
     */
    public function findOrphans() {
        // Get list of managed file URIs from the database.
        $public_scheme = 'public://';
        $managed_uris = [];
        $result = $this->database->query("SELECT uri FROM {file_managed} WHERE uri LIKE :scheme", [':scheme' => 'public://%']);
        if ($result) {
            $managed_list = $result->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($managed_list as $managed_uri) {
                $managed_uris[$managed_uri] = TRUE;
            }
        }

        // Recursively scan the public:// directory for all files.
        $all_files = $this->fileSystem->scanDirectory($public_scheme, '/.*/', ['recurse' => TRUE, 'key' => 'uri']);
        $patterns = $this->getIgnorePatterns();
        $orphans = [];
        foreach ($all_files as $uri => $file_info) {
            // Skip if this file is already managed.
            if (isset($managed_uris[$uri])) {
                continue;
            }
            // Determine relative path (strip scheme).
            $relative_path = substr($uri, strlen($public_scheme));
            // Check ignore patterns.
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
            // This file is an orphan.
            $orphans[] = $uri;
        }
        return $orphans;
    }

    /**
     * Adopts (registers) the given files as managed file entities (and media, if configured).
     *
     * @param string[] $file_uris
     *   Array of file URIs (public://...) to adopt.
     *
     * @return int
     *   The number of files successfully adopted.
     */
    public function adoptFiles(array $file_uris) {
        $count = 0;
        $config = $this->configFactory->get('file_adoption.settings');
        $add_to_media = $config->get('add_to_media');
        $media_enabled = \Drupal::service('module_handler')->moduleExists('media');
        foreach ($file_uris as $uri) {
            try {
                // Create a file entity for the orphaned file.
                $file = File::create([
                    'uri' => $uri,
                    'filename' => basename($uri),
                    'status' => 1,
                    'uid' => 0,
                ]);
                $file->save();
                // If configured, also create a Media entity for this file.
                if ($media_enabled && $add_to_media) {
                    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
                    $image_extensions = ['png', 'jpg', 'jpeg', 'gif'];
                    if (in_array($extension, $image_extensions)) {
                        $bundle = 'image';
                        $field = 'field_media_image';
                    }
                    else {
                        $bundle = 'document';
                        $field = 'field_media_file';
                    }
                    $filename = basename($uri);
                    $media_fields = [
                        'bundle' => $bundle,
                        'uid' => 0,
                        'name' => $filename,
                        'status' => 1,
                        $field => [
                            'target_id' => $file->id(),
                        ],
                    ];
                    if ($bundle === 'image') {
                        $media_fields[$field]['alt'] = $filename;
                    }
                    $media = \Drupal::entityTypeManager()->getStorage('media')->create($media_fields);
                    $media->save();
                }
                // Log the adoption in Drupal's system log.
                $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
                $count++;
            }
            catch (\Exception $e) {
                // Log any errors encountered during adoption.
                $this->logger->error('Failed to adopt file @file: @message', [
                    '@file' => $uri,
                    '@message' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

}
