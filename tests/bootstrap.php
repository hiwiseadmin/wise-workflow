<?php
declare(strict_types=1);

/**
 * Wise-Workflow 测试引导文件（自包含版本）
 *
 * 不依赖 Composer autoload，而是直接注册 PSR-4 自动加载器
 * 对 ThinkPHP 依赖类提供最小化的 stub（在无法加载完整框架时）
 */

// ==================== 加载 Mockery ====================
$mockeryBaseDir = __DIR__ . '/../../../../vendor/mockery/mockery/';
$mockeryMainFile = $mockeryBaseDir . 'Mockery.php';
$mockeryHelpersFile = $mockeryBaseDir . 'helpers.php';
$mockeryPsr4Dir = $mockeryBaseDir . 'Mockery'; // PSR-4: Mockery\ → Mockery/
if (!file_exists($mockeryMainFile)) {
    // 尝试 library 子目录
    $mockeryMainFile = $mockeryBaseDir . 'library/Mockery.php';
    $mockeryHelpersFile = $mockeryBaseDir . 'library/helpers.php';
    $mockeryPsr4Dir = $mockeryBaseDir . 'library/Mockery';
}
if (file_exists($mockeryMainFile)) {
    // 注册 Mockery PSR-4 自动加载: Mockery\ → Mockery/
    spl_autoload_register(function (string $class) use ($mockeryPsr4Dir): void {
        $prefix = 'Mockery\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $mockeryPsr4Dir . '/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
    // helpers.php（全局函数）
    if (file_exists($mockeryHelpersFile)) {
        require_once $mockeryHelpersFile;
    }
    require_once $mockeryMainFile;
}

// ==================== 尝试加载 Composer autoload ====================
$composerAutoload = __DIR__ . '/../../../../vendor/autoload.php';
$frameworkLoaded = false;

if (file_exists($composerAutoload)) {
    // 抑制可能的不完整 vendor 警告
    $prevErrorReporting = error_reporting(E_ALL & ~E_WARNING);
    try {
        require_once $composerAutoload;
        if (class_exists(\think\exception\ValidateException::class)) {
            $frameworkLoaded = true;
        }
    } catch (\Throwable $e) {
        // Composer autoload 损坏，回退到手动加载
    }
    error_reporting($prevErrorReporting);
}

// ==================== PSR-4 手动注册 ====================
// 始终注册 wise-workflow 的 PSR-4 命名空间
spl_autoload_register(function (string $class): void {
    // wise\workflow\xxx → packages/wiseadmin/wise-workflow/src/xxx.php
    $prefix = 'wise\\workflow\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// ==================== ThinkPHP Stubs（隔离数据库，始终尝试提供；各 stub 自带 class_exists 守卫）====================
    // 为 think\exception\ValidateException 创建最小化 stub
    if (!class_exists('think\exception\ValidateException', false)) {
        eval('namespace think\exception; class ValidateException extends \RuntimeException {}');
    }

    // 为 think\Model 创建最小化 stub
    if (!class_exists('think\Model', false)) {
        eval('
        namespace think;
        class Model {
            protected $name = "";
            protected $schema = [];
            protected $type = [];
            protected $autoWriteTimestamp = false;
            protected $createTime = "create_time";
            protected $updateTime = "update_time";
            protected $dateFormat = "datetime";
            public function where($field, $op = null, $value = null) { return $this; }
            public function find() { return null; }
            public function select() { return []; }
            public function insert($data) { return true; }
            public function update($data) { return true; }
            public function order($field, $order = "") { return $this; }
            public function limit($limit) { return $this; }
            public function toArray() { return []; }
        }
        ');
    }

    // 为 think\Facade 创建最小化 stub（GuardListener, DatabaseMarkingStore 的依赖）
    if (!class_exists('think\Facade', false)) {
        eval('
        namespace think;
        abstract class Facade {
            protected static function getFacadeClass() { return ""; }
            public static function __callStatic($method, $args) { return null; }
        }
        ');
    }

// 为 think\facade\Session 创建最小化 stub
if (!class_exists('think\facade\Session', false)) {
    eval('
    namespace think\facade;
    class Session extends \think\Facade {
        /**
         * @var array Stored values for test hooks
         */
        public static $testValues = [];
        
        public static function get($key) {
            if (array_key_exists($key, self::$testValues)) {
                return self::$testValues[$key];
            }
            return null;
        }
        
        public static function setForTest($key, $value) {
            self::$testValues[$key] = $value;
        }
        
        public static function clearForTest() {
            self::$testValues = [];
        }
    }
    ');
}

    // 为 think\Service 创建最小化 stub
    if (!class_exists('think\Service', false)) {
        eval('
        namespace think;
        class Service {
            protected $app;
            public function register() {}
            public function boot() {}
            protected function commands($commands) {}
        }
        ');
    }

    // think\Request 函数 stub
    if (!function_exists('request')) {
        eval('
        namespace {
            function request() {
                return new class {
                    public function ip() { return "127.0.0.1"; }
                    public function header($key) { return ""; }
                };
            }
        }
        ');
    }

// 为 think\console\Command 创建 stub（Init 命令的依赖）
if (!class_exists('think\console\Command', false)) {
    eval('
    namespace think\console;
    class Command {
        protected function configure() {}
        protected function execute(Input $input, Output $output) { return 0; }
    }
    class Input {}
    class Output {
        public function writeln($msg) {}
    }
    ');
}

// 为 app() 函数创建 stub
if (!function_exists('app')) {
    eval('
    namespace {
        function app($abstract = null) {
            static $instance = null;
            if ($instance === null) {
                $instance = new class {
                    public function getRootPath() { return ""; }
                    public function bind($abstract, $concrete) {}
                };
            }
            return $instance;
        }
    }
    ');
}

// ==================== config() 全局函数 stub ====================
// ThinkPHP 的 config() 辅助函数在测试桩环境中未提供，这里加载包的真实配置
// 并支持点号分隔查找与默认值回退，使引擎/监听器按真实行为运行。
if (!function_exists('config')) {
    eval('
    namespace {
        function config($key = null, $default = null) {
            static $configs = null;
            if ($configs === null) {
                $file = __DIR__ . "/../config/wise-workflow.php";
                $configs = file_exists($file) ? (require $file) : [];
            }
            // 安全审计阶段 1：允许各用例在 setUp 中覆盖配置（如 permission.allow_all）。
            // 每次调用都应用覆盖，避免首次调用即冻结缓存导致用例间互相影响；
            // 未设置时使用包默认值（fail-closed）。
            if (!empty($GLOBALS["__WF_CONFIG_OVERRIDE"]) && is_array($GLOBALS["__WF_CONFIG_OVERRIDE"])) {
                $configs = array_replace_recursive($configs, $GLOBALS["__WF_CONFIG_OVERRIDE"]);
            }
            if ($key === null) {
                return $configs;
            }
            if (is_array($configs) && array_key_exists($key, $configs)) {
                return $configs[$key];
            }
            if (is_string($key) && strpos($key, ".") !== false) {
                // 生产环境以 config("wise-workflow.xxx") 读取包配置，而本桩加载的就是
                // 包配置文件本身（顶层即 xxx），因此先按原样查，再剥掉包名前缀重试。
                $candidates = array($key, preg_replace("/^wise-workflow\./", "", $key));
                foreach ($candidates as $tryKey) {
                    $parts = explode(".", (string) $tryKey);
                    $val = $configs;
                    $found = true;
                    foreach ($parts as $part) {
                        if (is_array($val) && array_key_exists($part, $val)) {
                            $val = $val[$part];
                        } else {
                            $found = false;
                            break;
                        }
                    }
                    if ($found) {
                        return $val;
                    }
                }
                return $default;
            }
            return $default;
        }
    }
    ');
}
