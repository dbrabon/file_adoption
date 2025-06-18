<?php

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
     * Module handler service.
     *
     * @var \Drupal\Core\Extension\ModuleHandlerInterface
     */
    protected $moduleHandler;

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
     * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
     *   Module handler service.
     */
    public function __construct(FileSystemInterface $file_system, Connection $database, ConfigFactoryInterface $config_factory, LoggerInterface $logger, ModuleHandlerInterface $module_handler) {
        $this->fileSystem = $file_system;
        $this->database = $database;
        $this->configFactory = $config_factory;
        // Use the provided logger channel (file_adoption).
        $this->logger = $logger;
        $this->moduleHandler = $module_handler;
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
     * Scans the public files directory and processes each file sequentially.
     *
     * This method avoids building large in-memory lists by evaluating each file
     * as it is encountered. If $adopt is TRUE, eligible files are immediately
     * adopted.
     *
     * @param bool $adopt
     *   Whether matching orphan files should be adopted.
     *
     * @return array
     *   An associative array with the keys 'files', 'orphans' and 'adopted'.
     */
    public function scanAndProcess(bool $adopt = TRUE) {
        $counts = ['files' => 0, 'orphans' => 0, 'adopted' => 0];
        $patterns = $this->getIgnorePatterns();
        $add_to_media = $this->configFactory->get('file_adoption.settings')->get('add_to_media');
        $media_enabled = $this->moduleHandler->moduleExists('media');
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $counts;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
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

            if ($this->isManaged($uri)) {
                continue;
            }

            if ($add_to_media && $media_enabled && $this->isInMedia($uri)) {
                continue;
            }

            $counts['orphans']++;

            if ($adopt) {
                if ($this->adoptFile($uri)) {
                    $counts['adopted']++;
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
     *   Associative array with keys 'files', 'to_manage' and 'to_media'.
     */
    public function scanWithLists(int $limit = 500) {
        $results = ['files' => 0, 'to_manage' => [], 'to_media' => []];
        $patterns = $this->getIgnorePatterns();
        $add_to_media = $this->configFactory->get('file_adoption.settings')->get('add_to_media');
        $media_enabled = $this->moduleHandler->moduleExists('media');
        $public_realpath = $this->fileSystem->realpath('public://');

        if (!$public_realpath || !is_dir($public_realpath)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($public_realpath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
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

            if (!$this->isManaged($uri) && count($results['to_manage']) < $limit) {
                $results['to_manage'][] = $uri;
            }

            if ($media_enabled && $add_to_media && !$this->isInMedia($uri) && count($results['to_media']) < $limit) {
                $results['to_media'][] = $uri;
            }
        }

        return $results;
    }

    /**
     * Adopts (registers) the given files as managed file entities (and media, if configured).
     *
     * @param string[] $file_uris
     *   Array of file URIs (public://...) to adopt.
     *
     * @return int
     *   The number of newly created items.
     */
    public function adoptFiles(array $file_uris) {
        $count = 0;
        foreach ($file_uris as $uri) {
            if ($this->adoptFile($uri)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Adopts a single file and optionally creates a Media entity.
     *
     * @param string $uri
     *   The file URI to adopt.
     *
     * @return bool
     *   TRUE if a new file or media entity was created, FALSE otherwise.
     */
    public function adoptFile(string $uri) {
        $config = $this->configFactory->get('file_adoption.settings');
        $add_to_media = $config->get('add_to_media');
        $media_enabled = $this->moduleHandler->moduleExists('media');

        try {
            $new_item = FALSE;

            if ($this->isManaged($uri)) {
                $fid = $this->database->select('file_managed', 'fm')
                    ->fields('fm', ['fid'])
                    ->condition('uri', $uri)
                    ->range(0, 1)
                    ->execute()
                    ->fetchField();
                $file = File::load($fid);
                if (!$file) {
                    $file = File::create([
                        'uri' => $uri,
                        'filename' => basename($uri),
                        'status' => 1,
                        'uid' => 0,
                    ]);
                    $file->save();
                    $new_item = TRUE;
                }
            }
            else {
                $file = File::create([
                    'uri' => $uri,
                    'filename' => basename($uri),
                    'status' => 1,
                    'uid' => 0,
                ]);
                $file->save();
                $new_item = TRUE;
            }

            if ($media_enabled && $add_to_media && !$this->isInMedia($uri)) {
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
                $new_item = TRUE;
            }

            if ($new_item) {
                $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
            }
            return $new_item;
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
        $query = $this->database->select('file_managed', 'fm')
            ->fields('fm', ['fid'])
            ->condition('uri', $uri)
            ->range(0, 1);
        return (bool) $query->execute()->fetchField();
    }

    /**
     * Determines whether a file URI is already used by a Media entity.
     *
     * @param string $uri
     *   The file URI.
     *
     * @return bool
     *   TRUE if the file is referenced by a media entity, FALSE otherwise.
     */
    protected function isInMedia(string $uri): bool {
        $query = $this->database->select('file_managed', 'fm');
        $query->join('file_usage', 'fu', 'fu.fid = fm.fid');
        $query->addField('fu', 'fid');
        $query->condition('fm.uri', $uri);
        $query->condition('fu.type', 'media');
        $query->range(0, 1);
        return (bool) $query->execute()->fetchField();
    }

}
