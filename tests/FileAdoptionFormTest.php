<?php

namespace {
    require_once __DIR__ . '/FileScannerTest.php'; // reuse stub classes
}

namespace Drupal\Core\Form {
    interface FormStateInterface {}
    class FormState implements FormStateInterface {
        private array $storage = [];
        private array $values = [];
        public function get(string $key) { return $this->storage[$key] ?? NULL; }
        public function set(string $key, $value) { $this->storage[$key] = $value; }
        public function getValue(string $key) { return $this->values[$key] ?? NULL; }
        public function setValue(string $key, $value) { $this->values[$key] = $value; }
        public function setRebuild(bool $rebuild) {}
    }
    class ConfigFormBase {
        protected \Drupal\Core\Config\ConfigFactoryInterface $configFactory;
        public function __construct(\Drupal\Core\Config\ConfigFactoryInterface $factory) { $this->configFactory = $factory; }
        protected function config(string $name) { return $this->configFactory->get($name); }
        public function setConfigFactory(\Drupal\Core\Config\ConfigFactoryInterface $factory): void { $this->configFactory = $factory; }
        public function t(string $str, array $args = []): string { return strtr($str, $args); }
    }
}

namespace Drupal\Core\TempStore {
    class PrivateTempStore { private array $data = []; public function get($k) { return $this->data[$k] ?? NULL; } public function set($k,$v) { $this->data[$k]=$v; } public function delete($k) { unset($this->data[$k]); } }
    class PrivateTempStoreFactory { private PrivateTempStore $store; public function __construct() { $this->store = new PrivateTempStore(); } public function get($name) { return $this->store; } }
}

namespace Drupal\Core\Cache { class MemoryCache { public array $data=[]; public function get($k){return $this->data[$k]??FALSE;} public function set($k,$v,$e){$this->data[$k]=(object)['data'=>$v];} } }

namespace { use Drupal\Core\Cache\MemoryCache; class Drupal { public static MemoryCache $cache; public static function cache(){return self::$cache;} } }

namespace Drupal\file_adoption {
    require_once __DIR__ . '/../src/Form/FileAdoptionForm.php';
    use Drupal\Core\File\FileSystem;
    use Drupal\Core\TempStore\PrivateTempStoreFactory;
    use Drupal\Core\Config\ConfigFactory;
    use Drupal\Core\Form\FormState;
    use Drupal\Core\Cache\MemoryCache;
    use PHPUnit\Framework\TestCase;

    class RecordingScanner extends TestFileScanner {
        public int $countCalls = 0;
        public function __construct(string $path) { parent::__construct($path); }
        public function countFiles(string $rel = ''): int { $this->countCalls++; return 0; }
    }

    class FileAdoptionFormTest extends TestCase {
        public function testPreviewSkippedWithoutCache() {
            $scanner = new RecordingScanner(sys_get_temp_dir());
            $fs = new FileSystem(sys_get_temp_dir());
            $tempFactory = new PrivateTempStoreFactory();
            $config = new ConfigFactory([
                'ignore_patterns' => '',
                'enable_adoption' => false,
                'follow_symlinks' => false,
                'items_per_run' => 20,
                'cache_lifetime' => 86400,
            ]);
            \Drupal::$cache = new MemoryCache();
            $form = new Form\FileAdoptionForm($scanner, $fs, $tempFactory);
            $form->setConfigFactory($config);
            $state = new FormState();
            $built = $form->buildForm([], $state);
            $this->assertEquals(0, $scanner->countCalls);
            $this->assertStringContainsString('Run a scan', $built['preview']['markup']['#markup']);
        }
    }
}
