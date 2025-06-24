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
    if ($public_path) {
      $file_count = $this->fileScanner->countFiles();
    }

    $preview = [];
    if ($public_path && is_dir($public_path)) {
      $entries = scandir($public_path);
      $patterns = $this->fileScanner->getIgnorePatterns();
      $matched_patterns = [];

      $find_first_file = function ($dir) {
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
      };

      $root_first = $find_first_file($public_path);
      $root_label = 'public://';
      if ($root_first) {
        $root_label .= ' (e.g., ' . $root_first . ')';
      }
      $root_count = 0;
      foreach ($entries as $entry_check) {
        if ($entry_check === '.' || $entry_check === '..' || str_starts_with($entry_check, '.')) {
          continue;
        }
        $absolute = $public_path . DIRECTORY_SEPARATOR . $entry_check;
        if (is_file($absolute)) {
          $ignored = FALSE;
          foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $entry_check)) {
              $ignored = TRUE;
              $matched_patterns[$pattern] = TRUE;
              break;
            }
          }
          if (!$ignored) {
            $root_count++;
          }
        }
      }
      if ($root_count > 0) {
        $root_label .= ' (' . $root_count . ')';
      }

      $preview[] = '<li>' . Html::escape($root_label) . '</li>';

      foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
          continue;
        }

        $absolute = $public_path . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($absolute)) {
          $relative_path = $entry . '/*';
          $first_file = $find_first_file($absolute);
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
          if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $entry)) {
            $matched = $pattern;
            $matched_patterns[$pattern] = TRUE;
            break;
          }
        }

        if (is_dir($absolute)) {
          if ($matched) {
            $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          }
          else {
            $count_dir = $this->fileScanner->countFiles($entry);
            if ($count_dir > 0) {
              $label .= ' (' . $count_dir . ')';
            }
            $preview[] = '<li>' . Html::escape($label) . '</li>';
          }
        }
        elseif ($matched) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
        }
      }

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

}
