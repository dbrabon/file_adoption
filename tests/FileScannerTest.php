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
    class RealFileSystem implements FileSystemInterface {
        private string $path;
        public function __construct(string $path) { $this->path = rtrim($path, '/'); }
        public function realpath(string $uri) {
            if (str_starts_with($uri, 'public://')) {
                $rel = substr($uri, 9);
                return $this->path . ($rel ? '/' . $rel : '');
            }
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

    class QueryResult implements \IteratorAggregate {
        private array $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        public function fetchField() { if (!$this->rows) { return FALSE; } $row = (array) $this->rows[0]; return reset($row); }
    }

    class Select {
        private \PDO $pdo; private string $table; private array $fields = []; private array $conds = []; private ?array $range = NULL; private ?string $expr = NULL; private array $order = [];
        public function __construct(\PDO $pdo, string $table) { $this->pdo = $pdo; $this->table = $table; }
        public function fields(string $alias, array $fields) { $this->fields = $fields; return $this; }
        public function addExpression(string $expr) { $this->expr = $expr; return $this; }
        public function condition(string $field, $value, string $op = '=') { $this->conds[] = [$field, $value, $op]; return $this; }
        public function range(int $start, int $len) { $this->range = [$start, $len]; return $this; }
        public function orderBy(string $field, string $dir = 'ASC') { $this->order = [$field, $dir]; return $this; }
        public function execute(): QueryResult {
            $cols = $this->expr ?: ($this->fields ? implode(',', $this->fields) : '*');
            $sql = "SELECT $cols FROM {$this->table}";
            $vals = [];
            if ($this->conds) {
                $parts = [];
                foreach ($this->conds as [$f,$v,$o]) { $parts[] = "$f $o ?"; $vals[] = $v; }
                $sql .= ' WHERE ' . implode(' AND ', $parts);
            }
            if ($this->order) { $sql .= " ORDER BY {$this->order[0]} {$this->order[1]}"; }
            if ($this->range) { $sql .= " LIMIT {$this->range[1]} OFFSET {$this->range[0]}"; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($vals);
            $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
            return new QueryResult($rows);
        }
    }

    class Merge {
        private \PDO $pdo; private string $table; private array $key = []; private array $fields = [];
        public function __construct(\PDO $pdo, string $table) { $this->pdo = $pdo; $this->table = $table; }
        public function key(array $key) { $this->key = $key; return $this; }
        public function fields(array $fields) { $this->fields = $fields; return $this; }
        public function execute() {
            $where = []; $vals = [];
            foreach ($this->key as $k => $v) { $where[] = "$k=?"; $vals[] = $v; }
            $chk = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where));
            $chk->execute($vals);
            $exists = $chk->fetchColumn() > 0;
            if ($exists) {
                $sets = []; $svals = [];
                foreach ($this->fields as $f => $v) { $sets[] = "$f=?"; $svals[] = $v; }
                $sql = "UPDATE {$this->table} SET " . implode(',', $sets) . " WHERE " . implode(' AND ', $where);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge($svals, $vals));
            }
            else {
                $cols = array_merge(array_keys($this->key), array_keys($this->fields));
                $sql = "INSERT INTO {$this->table}(" . implode(',', $cols) . ") VALUES(" . rtrim(str_repeat('?,', count($cols)), ',') . ")";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge(array_values($this->key), array_values($this->fields)));
            }
        }
    }

    class Update {
        private \PDO $pdo; private string $table; private array $fields = []; private array $conds = [];
        public function __construct(\PDO $pdo, string $table) { $this->pdo = $pdo; $this->table = $table; }
        public function fields(array $fields) { $this->fields = $fields; return $this; }
        public function condition(string $field, $value, string $op = '=') { $this->conds[] = [$field, $value, $op]; return $this; }
        public function execute() {
            $parts = []; $vals = [];
            foreach ($this->fields as $f => $v) { $parts[] = "$f=?"; $vals[] = $v; }
            $sql = "UPDATE {$this->table} SET " . implode(',', $parts);
            if ($this->conds) {
                $c = []; $cvals = [];
                foreach ($this->conds as [$f,$v,$o]) { $c[] = "$f $o ?"; $cvals[] = $v; }
                $sql .= ' WHERE ' . implode(' AND ', $c);
                $vals = array_merge($vals, $cvals);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($vals);
        }
    }

    class Delete {
        private \PDO $pdo; private string $table; private array $conds = [];
        public function __construct(\PDO $pdo, string $table) { $this->pdo = $pdo; $this->table = $table; }
        public function condition(string $field, $value, string $op = '=') { $this->conds[] = [$field, $value, $op]; return $this; }
        public function execute() {
            $sql = "DELETE FROM {$this->table}";
            $vals = [];
            if ($this->conds) {
                $c = [];
                foreach ($this->conds as [$f,$v,$o]) { $c[] = "$f $o ?"; $vals[] = $v; }
                $sql .= ' WHERE ' . implode(' AND ', $c);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($vals);
        }
    }

    class SqliteConnection extends Connection {
        private \PDO $pdo;
        public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
        public function select(string $table, string $alias = '') { return new Select($this->pdo, $table); }
        public function merge(string $table) { return new Merge($this->pdo, $table); }
        public function update(string $table) { return new Update($this->pdo, $table); }
        public function delete(string $table) { return new Delete($this->pdo, $table); }
    }
}

namespace Drupal\file_adoption {
    require_once __DIR__ . '/../src/Util/UriHelper.php';
    require_once __DIR__ . '/../src/FileScanner.php';
    require_once __DIR__ . '/../src/InventoryManager.php';

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

    class DbFileScanner extends FileScanner {
        public function __construct(string $path, \PDO $pdo, string $patterns = '', \Psr\Log\LoggerInterface $logger = null) {
            $fs = new \Drupal\Core\File\RealFileSystem($path);
            $cfg = new \Drupal\Core\Config\ConfigFactory(['ignore_patterns' => $patterns, 'follow_symlinks' => false]);
            $db = new \Drupal\Core\Database\SqliteConnection($pdo);
            $logger = $logger ?: new \Psr\Log\NullLogger();
            parent::__construct($fs, $db, $cfg, $logger);
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
    use Drupal\file_adoption\DbFileScanner;
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

        private function createDatabase(): \PDO {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE file_adoption_dir (id INTEGER PRIMARY KEY AUTOINCREMENT, uri TEXT UNIQUE, modified INTEGER, ignore INTEGER DEFAULT 0)");
            $pdo->exec("CREATE TABLE file_adoption_file (id INTEGER PRIMARY KEY AUTOINCREMENT, uri TEXT UNIQUE, modified INTEGER, ignore INTEGER DEFAULT 0, managed INTEGER DEFAULT 0, parent_dir INTEGER)");
            $pdo->exec("CREATE TABLE file_managed (fid INTEGER PRIMARY KEY AUTOINCREMENT, uri TEXT UNIQUE)");
            return $pdo;
        }

        public function testDatabaseTablesPopulated() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            mkdir($dir . '/sub');
            file_put_contents($dir . '/a.txt', 'a');
            file_put_contents($dir . '/b.txt', 'b');
            file_put_contents($dir . '/sub/c.txt', 'c');

            $pdo = $this->createDatabase();
            $scanner = new DbFileScanner($dir, $pdo);
            $results = $scanner->scanWithLists(10);

            $this->assertEquals(3, $results['files']);
            $this->assertEquals(3, $results['orphans']);
            $this->assertCount(3, $results['to_manage']);

            $dirCount = $pdo->query("SELECT COUNT(*) FROM file_adoption_dir")->fetchColumn();
            $fileCount = $pdo->query("SELECT COUNT(*) FROM file_adoption_file")->fetchColumn();
            $this->assertEquals(2, $dirCount); // public:// and sub dir
            $this->assertEquals(3, $fileCount);

            unlink($dir . '/a.txt');
            unlink($dir . '/b.txt');
            unlink($dir . '/sub/c.txt');
            rmdir($dir . '/sub');
            rmdir($dir);
        }

        public function testIgnoreAndManagedPersist() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            mkdir($dir . '/skip');
            file_put_contents($dir . '/managed.txt', 'm');
            file_put_contents($dir . '/skip/ignored.txt', 'i');
            file_put_contents($dir . '/new.txt', 'n');

            $pdo = $this->createDatabase();
            $mtimeSkip = filemtime($dir . '/skip/ignored.txt');
            $pdo->exec("INSERT INTO file_adoption_dir(uri, modified, ignore) VALUES ('public://skip', $mtimeSkip, 1)");
            $pdo->exec("INSERT INTO file_adoption_file(uri, modified, ignore, managed, parent_dir) VALUES ('public://skip/ignored.txt', $mtimeSkip, 1, 0, 0)");
            $mtimeManaged = filemtime($dir . '/managed.txt');
            $pdo->exec("INSERT INTO file_adoption_file(uri, modified, ignore, managed, parent_dir) VALUES ('public://managed.txt', $mtimeManaged, 0, 1, 0)");

            $scanner = new DbFileScanner($dir, $pdo);
            $results = $scanner->scanWithLists(10);

            $this->assertEquals(2, $results['files']);
            $this->assertEquals(1, $results['orphans']);
            $this->assertEquals(['public://new.txt'], $results['to_manage']);

            $managed = $pdo->query("SELECT managed FROM file_adoption_file WHERE uri='public://managed.txt'")->fetchColumn();
            $ignore = $pdo->query("SELECT ignore FROM file_adoption_file WHERE uri='public://skip/ignored.txt'")->fetchColumn();
            $this->assertEquals(1, $managed);
            $this->assertEquals(1, $ignore);

            unlink($dir . '/managed.txt');
            unlink($dir . '/skip/ignored.txt');
            unlink($dir . '/new.txt');
            rmdir($dir . '/skip');
            rmdir($dir);
        }

        public function testCleanupRemovesStaleRecords() {
            $dir = sys_get_temp_dir() . '/fs_test_' . uniqid();
            mkdir($dir);
            mkdir($dir . '/keep');
            file_put_contents($dir . '/keep/keep.txt', 'k');

            $pdo = $this->createDatabase();
            $pdo->exec("INSERT INTO file_adoption_dir(uri, modified, ignore) VALUES ('public://keep', 0, 0)");
            $pdo->exec("INSERT INTO file_adoption_dir(uri, modified, ignore) VALUES ('public://gone', 0, 0)");
            $pdo->exec("INSERT INTO file_adoption_file(uri, modified, ignore, managed, parent_dir) VALUES ('public://keep/keep.txt', 0, 0, 0, 0)");
            $pdo->exec("INSERT INTO file_adoption_file(uri, modified, ignore, managed, parent_dir) VALUES ('public://gone/missing.txt', 0, 0, 0, 0)");

            $scanner = new DbFileScanner($dir, $pdo);
            $fs = new \Drupal\Core\File\RealFileSystem($dir);
            $db = new \Drupal\Core\Database\SqliteConnection($pdo);
            $inventory = new \Drupal\file_adoption\InventoryManager($db, $fs, $scanner);

            $removedDirs = $inventory->cleanupDirectories();
            $removedFiles = $inventory->cleanupFiles();

            $this->assertEquals(1, $removedDirs);
            $this->assertEquals(1, $removedFiles);

            $dirCount = $pdo->query("SELECT COUNT(*) FROM file_adoption_dir")->fetchColumn();
            $fileCount = $pdo->query("SELECT COUNT(*) FROM file_adoption_file")->fetchColumn();
            $this->assertEquals(1, $dirCount);
            $this->assertEquals(1, $fileCount);

            unlink($dir . '/keep/keep.txt');
            rmdir($dir . '/keep');
            rmdir($dir);
        }
    }
}
