<?php

namespace Drupal\file_adoption\Util;

/**
 * Utility methods for working with file URIs.
 */
class UriHelper {

    /**
     * Gets the parent directory URI of the given file or directory URI.
     */
    public static function getParentDir(string $uri): string {
        if (str_starts_with($uri, 'public://')) {
            $relative = substr($uri, 9);
            $dir = dirname($relative);
            return $dir === '.' ? 'public://' : 'public://' . $dir;
        }
        $dir = dirname($uri);
        return $dir === '.' ? '' : $dir;
    }

    /**
     * Checks if the provided relative URI matches any ignore pattern.
     */
    public static function matchesIgnore(string $relative_uri, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $relative_uri)) {
                return TRUE;
            }
        }
        return FALSE;
    }
}
