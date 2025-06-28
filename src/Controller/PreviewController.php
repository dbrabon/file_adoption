<?php

namespace Drupal\file_adoption\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\file_adoption\FileScanner;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\State\StateInterface;
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
   * State service for stored scan results.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs the controller.
   */
  public function __construct(FileScanner $fileScanner, FileSystemInterface $fileSystem, StateInterface $state) {
    $this->fileScanner = $fileScanner;
    $this->fileSystem = $fileSystem;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_adoption.file_scanner'),
      $container->get('file_system'),
      $container->get('state')
    );
  }

  /**
   * Gets cached file counts per directory or triggers a scan.
   */
  private function loadCounts(): array {
    $results = $this->state->get('file_adoption.scan_results') ?? [];
    $counts = $results['dir_counts'] ?? [];
    if (empty($counts)) {
      $counts = $this->fileScanner->countFilesByDirectory();
    }
    return $counts;
  }

  /**
   * Returns a directory inventory.
   */
  public function dirs(): JsonResponse {
    $depth = (int) $this->config('file_adoption.settings')->get('folder_depth');
    if ($depth <= 0) {
      $depth = PHP_INT_MAX;
    }
    $dirs = $this->fileScanner->getDirectoryInventory($depth);
    return new JsonResponse(['dirs' => $dirs]);
  }

  /**
   * Returns example files for each directory.
   */
  public function examples(): JsonResponse {
    $depth = (int) $this->config('file_adoption.settings')->get('folder_depth');
    if ($depth <= 0) {
      $depth = PHP_INT_MAX;
    }
    $dirs = $this->fileScanner->getDirectoryInventory($depth);
    array_unshift($dirs, '');
    $data = $this->fileScanner->collectFolderData($dirs);
    return new JsonResponse(['examples' => $data['examples']]);
  }

  /**
   * Returns file counts per directory using cached scan data when available.
   */
  public function counts(): JsonResponse {
    $counts = $this->loadCounts();
    return new JsonResponse(['counts' => $counts]);
  }

  /**
   * Returns the number of orphaned files from the last scan.
   */
  public function pendingCount(): JsonResponse {
    $results = $this->state->get('file_adoption.scan_results') ?? [];
    $count = (int) ($results['orphans'] ?? 0);
    return new JsonResponse(['count' => $count]);
  }

  /**
   * Provides preview markup as JSON.
   */
  public function preview(): JsonResponse {
    $public_path = $this->fileSystem->realpath('public://');
    $dir_counts = [];
    $file_count = 0;
    if ($public_path) {
      $dir_counts = $this->loadCounts();
      $file_count = $dir_counts[''] ?? 0;
    }

    $preview = [];
    if ($public_path && is_dir($public_path)) {
      $iterator = new \DirectoryIterator($public_path);
      $patterns = $this->fileScanner->getIgnorePatterns();
      $matched_patterns = [];
      $ignored_paths = [];

      $visited = [];
      $real_public = realpath($public_path);
      if ($real_public) {
        $visited[$real_public] = TRUE;
      }

      $root_first = $this->fileScanner->firstFile('', $visited);
      $root_label = 'public://';
      if ($root_first) {
        $root_label .= ' (e.g., ' . $root_first . ')';
      }
      $root_count = 0;
      foreach ($iterator as $fileinfo) {
        $entry_check = $fileinfo->getFilename();
        if ($fileinfo->isDot() || str_starts_with($entry_check, '.')) {
          continue;
        }
        if ($fileinfo->isLink()) {
          $real = realpath($fileinfo->getPathname());
          if ($real && isset($visited[$real])) {
            continue;
          }
        }
        if ($fileinfo->isFile()) {
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

      $iterator = new \DirectoryIterator($public_path);
      foreach ($iterator as $fileinfo) {
        $entry = $fileinfo->getFilename();
        if ($fileinfo->isDot() || str_starts_with($entry, '.')) {
          continue;
        }

        if ($fileinfo->isLink()) {
          $real = realpath($fileinfo->getPathname());
          if ($real && isset($visited[$real])) {
            continue;
          }
        }

        $absolute = $fileinfo->getPathname();
        $real_abs = realpath($absolute);
        if ($real_abs && !isset($visited[$real_abs])) {
          $visited[$real_abs] = TRUE;
        }

        if ($fileinfo->isDir()) {
          $relative_path = $entry . '/*';
          $first_file = $this->fileScanner->firstFile($entry, $visited);
          $label = $entry . '/';
          $plain = $entry . '/';
          if ($first_file) {
            $label .= ' (e.g., ' . $first_file . ')';
          }
        }
        else {
          $relative_path = $entry;
          $label = $entry;
          $plain = $entry;
        }

        $matched = '';
        foreach ($patterns as $pattern) {
          if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $entry)) {
            $matched = $pattern;
            $matched_patterns[$pattern] = TRUE;
            break;
          }
        }

        if ($fileinfo->isDir()) {
          if ($matched) {
            $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
            $ignored_paths[$plain] = TRUE;
          }
          else {
            $count_dir = $dir_counts[$entry] ?? 0;
            if ($count_dir > 0) {
              $label .= ' (' . $count_dir . ')';
            }
            $preview[] = '<li>' . Html::escape($label) . '</li>';
          }
        }
        elseif ($matched) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($label) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          $ignored_paths[$plain] = TRUE;
        }
      }

      $iterator_all = $this->fileScanner->getIterator($public_path, $visited);
      foreach ($iterator_all as $info) {
        $sub_path = str_replace('\\', '/', $iterator_all->getSubPathname());
        if ($sub_path === '' || preg_match('/(^|\/)(\.|\.{2})/', $sub_path)) {
          continue;
        }

        if ($info->isDir()) {
          $check_path = $sub_path . '/*';
          $display = $sub_path . '/';
        }
        else {
          $check_path = $sub_path;
          $display = $sub_path;
        }

        $matched = '';
        foreach ($patterns as $pattern) {
          if (fnmatch($pattern, $check_path) || fnmatch($pattern, $info->getFilename())) {
            $matched = $pattern;
            $matched_patterns[$pattern] = TRUE;
            break;
          }
        }

        if ($matched && !isset($ignored_paths[$display])) {
          $preview[] = '<li><span style="color:gray">' . Html::escape($display) . ' (matches pattern ' . Html::escape($matched) . ')</span></li>';
          $ignored_paths[$display] = TRUE;
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
