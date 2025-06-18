<?php

namespace Drupal\file_adoption;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

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
    // Split the patterns string by newline or comma.
    if (is_array($raw_patterns)) {
      $patterns = $raw_patterns;
    }
    else {
      $patterns = preg_split('/[\r\n,]+/', $raw_patterns);
    }
    // Trim whitespace and remove empty values.
    $patterns = array_map('trim', $patterns);
    $patterns = array_filter($patterns, function($value) {
      return $value !== '';
    });
    return $patterns;
  }

  /**
   * Scans the public:// file directory for orphaned files.
   *
   * @return string[]
   *   An array of file URIs (public://...) that are not managed and not ignored.
   */
  public function findOrphans() {
    $orphans = [];
    $public_scheme = 'public://';
    $public_path = $this->fileSystem->realpath($public_scheme);
    if (empty($public_path) || !is_dir($public_path)) {
      return $orphans;
    }
    // Get list of ignore patterns.
    $patterns = $this->getIgnorePatterns();

    // Retrieve all managed file URIs in public:// from the database.
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
   * Adopts (registers) the given files as managed file entities.
   *
   * @param string[] $file_uris
   *   Array of file URIs (public://...) to adopt.
   *
   * @return int
   *   The number of files successfully adopted.
   */
  public function adoptFiles(array $file_uris) {
    $count = 0;
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
        // Log the adoption in Drupal's system log.
        $this->logger->notice('Adopted orphan file @file', ['@file' => $uri]);
        // Determine the MIME type of the file and select appropriate media bundle.
        $mime_type = \Drupal::service('file.mime_type.guesser')->guess($uri);
        if (empty($mime_type)) {
          $mime_type = 'application/octet-stream';
        }
        // Choose media bundle based on MIME type.
        $media_bundle = NULL;
        $media_field = NULL;
        $filename = basename($uri);
        if (strpos($mime_type, 'image/') === 0) {
          $media_bundle = 'image';
          $media_field = 'field_media_image';
          $media_field_value = [
            [
              'target_id' => $file->id(),
              'alt' => $filename,
              'title' => $filename,
            ]
          ];
        }
        elseif (strpos($mime_type, 'audio/') === 0) {
          $media_bundle = 'audio';
          $media_field = 'field_media_audio_file';
          $media_field_value = [
            [
              'target_id' => $file->id(),
            ]
          ];
        }
        elseif (strpos($mime_type, 'video/') === 0) {
          $media_bundle = 'video';
          $media_field = 'field_media_video_file';
          $media_field_value = [
            [
              'target_id' => $file->id(),
            ]
          ];
        }
        else {
          // Default to 'document' bundle for other file types.
          $media_bundle = 'document';
          $media_field = 'field_media_document';
          $media_field_value = [
            [
              'target_id' => $file->id(),
            ]
          ];
        }
        // Create a media entity for the file.
        if ($media_bundle && $media_field) {
          try {
            $media = Media::create([
              'bundle' => $media_bundle,
              'uid' => 0,
              'status' => 1,
              'name' => $filename,
              $media_field => $media_field_value,
            ]);
            $media->save();
            // Log the media creation in Drupal's system log.
            $this->logger->notice('Created media @bundle for file @file', ['@bundle' => $media_bundle, '@file' => $uri]);
          }
          catch (\Exception $e) {
            // Log any errors encountered during media creation and continue.
            $this->logger->error('Failed to create media for file @file: @error', [
              '@file' => $uri,
              '@error' => $e->getMessage(),
            ]);
          }
        }
        $count++;
      }
      catch (\Exception $e) {
        // Log any errors encountered during adoption and continue.
        $this->logger->error('Failed to adopt file @file: @error', [
          '@file' => $uri,
          '@error' => $e->getMessage(),
        ]);
      }
    }
    return $count;
  }

}
