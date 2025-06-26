<?php
namespace org\bovigo\vfs;
class vfsStreamDirectory {
    private string $path;
    public function __construct(string $path) { $this->path = $path; }
    public function url(): string { return $this->path; }
    public function addChild(vfsStreamDirectory $child): void { }
}
class vfsStream {
    public static function setup(string $root, $permissions = null, array $structure = []): vfsStreamDirectory {
        $base = sys_get_temp_dir() . '/vfs_' . uniqid();
        $dir = $base . '/' . $root;
        mkdir($dir, 0777, true);
        foreach ($structure as $name => $content) {
            $path = $dir . '/' . $name;
            if (is_array($content)) {
                mkdir($path, 0777, true);
            } else {
                file_put_contents($path, $content);
            }
        }
        return new vfsStreamDirectory($dir);
    }
    public static function newDirectory(string $name): vfsStreamDirectory {
        $path = sys_get_temp_dir() . '/vfs_' . uniqid() . '/' . $name;
        mkdir($path, 0777, true);
        return new vfsStreamDirectory($path);
    }
    public static function url(string $path): string {
        return $path;
    }
}
