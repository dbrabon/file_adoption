<?php

// Define minimal stubs for required Drupal classes and interfaces
namespace Drupal\Core\File {
    interface FileSystemInterface {
        public function realpath(string $uri);
    }
    class FileSystem implements FileSystemInterface {
        private $path;
        public function __construct(string $path) { $this->path = $path; }
        public function realpath(string $uri) {
            if ($uri === 'public://') { return $this->path; }
            return FALSE;
        }
    }
}

namespace Drupal\Core\Config {
    interface ConfigFactoryInterface { public function get(string $name); }
    class Config {
        private array $data;
        public function __construct(array $data) { $this->data = $data; }
        public function get(string $key) { return $this->data[$key] ?? ''; }
    }
    class ConfigFactory implements ConfigFactoryInterface {
        private Config $config;
        public function __construct(array $data) { $this->config = new Config($data); }
        public function get(string $name) { return $this->config; }
    }
}

namespace Psr\Log {
    interface LoggerInterface {
        public function emergency($message, array $context = []);
        public function alert($message, array $context = []);
        public function critical($message, array $context = []);
        public function error($message, array $context = []);
        public function warning($message, array $context = []);
        public function notice($message, array $context = []);
        public function info($message, array $context = []);
        public function debug($message, array $context = []);
        public function log($level, $message, array $context = []);
    }
    class NullLogger implements LoggerInterface {
        public function emergency($message, array $context = []){}
        public function alert($message, array $context = []){}
        public function critical($message, array $context = []){}
        public function error($message, array $context = []){}
        public function warning($message, array $context = []){}
        public function notice($message, array $context = []){}
        public function info($message, array $context = []){}
        public function debug($message, array $context = []){}
        public function log($level, $message, array $context = []){}
    }
    class TestLogger extends NullLogger {
        public array $errors = [];
        public function error($message, array $context = []) {
            $this->errors[] = $message;
        }
    }
}

namespace Drupal\Core\Database {
    class Connection {}
}

namespace Drupal\file_adoption {
    require_once __DIR__ . '/../src/FileScanner.php';

    class TestFileScanner extends FileScanner {
        public function __construct(string $path, \Psr\Log\LoggerInterface $logger = null) {
            $fs = new \Drupal\Core\File\FileSystem($path);
            $cfg = new \Drupal\Core\Config\ConfigFactory(['ignore_patterns' => '']);
            $db = new \Drupal\Core\Database\Connection();
            $logger = $logger ?: new \Psr\Log\NullLogger();
            parent::__construct($fs, $db, $cfg, $logger);
        }
        protected function loadManagedUris(): void {
            $this->managedUris = [];
            $this->managedLoaded = TRUE;
        }
    }

    class FailingFileScanner extends TestFileScanner {
        private bool $fail = true;
        public function adoptFile(string $uri): bool {
            if ($this->fail) {
                $this->fail = false;
                throw new \Exception('fail');
            }
            return true;
        }
    }
}

namespace Drupal\file_adoption\Tests {
    use Drupal\file_adoption\TestFileScanner;
    use Drupal\file_adoption\FailingFileScanner;
    use Psr\Log\TestLogger;
    use PHPUnit\Framework\TestCase;

    class FileScannerTest extends TestCase {
        public function testCountsContinueBeyondLimit() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            file_put_contents($dir . '/a.txt', 'a');
            file_put_contents($dir . '/b.txt', 'b');
            file_put_contents($dir . '/c.txt', 'c');

            $scanner = new TestFileScanner($dir);
            $results = $scanner->scanWithLists(1);

            $this->assertEquals(3, $results['files']);
            $this->assertEquals(3, $results['orphans']);
            $this->assertCount(1, $results['to_manage']);
            $this->assertEquals(0, $results['errors']);

            unlink($dir . '/a.txt');
            unlink($dir . '/b.txt');
            unlink($dir . '/c.txt');
            rmdir($dir);
        }

        public function testScanChunkProcessesInBatches() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            file_put_contents($dir . '/a.txt', 'a');
            file_put_contents($dir . '/b.txt', 'b');
            file_put_contents($dir . '/c.txt', 'c');

            $scanner = new TestFileScanner($dir);
            $batch1 = $scanner->scanChunk(0, 2);
            $batch2 = $scanner->scanChunk($batch1['offset'], 2);

            $this->assertEquals(2, $batch1['results']['files']);
            $this->assertEquals(1, $batch2['results']['files']);
            $total_files = $batch1['results']['files'] + $batch2['results']['files'];
            $this->assertEquals(3, $total_files);
            $this->assertEquals(0, $batch1['results']['errors']);
            $this->assertEquals(0, $batch2['results']['errors']);

            unlink($dir . '/a.txt');
            unlink($dir . '/b.txt');
            unlink($dir . '/c.txt');
            rmdir($dir);
        }

        public function testScanAndProcessLogsErrorAndContinues() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            file_put_contents($dir . '/a.txt', 'a');
            file_put_contents($dir . '/b.txt', 'b');

            $logger = new TestLogger();
            $scanner = new FailingFileScanner($dir, $logger);
            $results = $scanner->scanAndProcess(true);

            $this->assertEquals(2, $results['files']);
            $this->assertEquals(2, $results['orphans']);
            $this->assertEquals(1, $results['adopted']);
            $this->assertEquals(1, $results['errors']);
            $this->assertCount(1, $logger->errors);

            unlink($dir . '/a.txt');
            unlink($dir . '/b.txt');
            rmdir($dir);
        }
    }
}
