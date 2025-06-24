<?php

namespace Drupal\file_adoption\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns preview markup for the file adoption form.
 */
class PreviewController extends ControllerBase {

  /**
   * The file scanner service.
   *
   * @var \Drupal\file_adoption\FileScanner
   */
  protected $fileScanner;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs the controller.
   */
  public function __construct(FileScanner $fileScanner, FileSystemInterface $fileSystem) {
    $this->fileScanner = $fileScanner;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_system')
    );
  }

  /**
   * Provides preview markup as JSON.
   */
  public function preview(): JsonResponse {
    $public_path = $this->fileSystem->realpath('public://');
    $file_count = 0;
    $dir_counts = [];
    if ($public_path) {
      $dir_counts = $this->fileScanner->countFilesByDirectory();
      $file_count = $dir_counts[''] ?? 0;
    }

    $preview = [];
    if ($public_path && is_dir($public_path)) {
      $iterator = new \DirectoryIterator($public_path);
      $patterns = $this->fileScanner->getIgnorePatterns();

      $matched_patterns = [];
      $entries = [];

      $root_first = $this->findFirstFile($public_path);
      $root_label = 'public://';
      if ($root_first) {
        $root_label .= ' (e.g., ' . $root_first . ')';
      }

      $root_count = 0;
      foreach ($iterator as $fileinfo) {
        $entry = $fileinfo->getFilename();
        if ($fileinfo->isDot() || str_starts_with($entry, '.')) {
          continue;
        }

        $absolute = $fileinfo->getPathname();
        if ($fileinfo->isDir()) {
          $relative_path = $entry . '/*';
          $first_file = $this->findFirstFile($absolute);
          $label = $entry . '/';
          if ($first_file) {
            $label .= ' (e.g., ' . $first_file . ')';
          }
        }
        else {
          $relative_path = $entry;
          $label = $entry;
        }

        $matched = '';
        foreach ($patterns as $pattern) {
          if ($pattern !== '' && (fnmatch($pattern, $relative_path) || fnmatch($pattern, $entry))) {
            $matched = $pattern;
            $matched_patterns[$pattern] = TRUE;
            break;
          }
        }

        if ($fileinfo->isFile()) {
          if (!$matched) {
            $root_count++;
          }
          elseif ($matched) {
            $entries[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          }
        }
        else { // Directory
          if ($matched) {
            $entries[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          }
          else {
            $count_dir = $dir_counts[$entry] ?? 0;
            if ($count_dir > 0) {
              $label .= ' (' . $count_dir . ')';
            }
            $entries[] = '<li>' . Html::escape($label) . '</li>';
          }
        }
      }

      if ($root_count > 0) {
        $root_label .= ' (' . $root_count . ')';
      }

      $preview[] = '<li>' . Html::escape($root_label) . '</li>';
      $preview = array_merge($preview, $entries);

      foreach ($patterns as $pattern) {
        if (!isset($matched_patterns[$pattern])) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($pattern) . ' (pattern not found)</span></li>';
        }
      }
    }

    $list_html = '';
    if (!empty($preview)) {
      $list_html = '<ul>' . implode('', $preview) . '</ul>';
      $list_html = '<div>' . $list_html . '</div>';
    }

    return new JsonResponse([
      'markup' => $list_html,
      'count' => $file_count,
    ]);
  }

  /**
   * Finds the first non-hidden file directly within the given directory.
   *
   * @param string $dir
   *   The absolute directory path to search.
   *
   * @return string|null
   *   The name of the first visible file, or NULL if none found or the
   *   directory does not exist.
   */
  private function findFirstFile(string $dir): ?string {
    if (!is_dir($dir)) {
      return NULL;
    }
    $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
    foreach ($it as $file) {
      if ($file->isFile()) {
        $name = $file->getFilename();
        if (!str_starts_with($name, '.')) {
          return $name;
        }
      }
    }
    return NULL;
  }

}
