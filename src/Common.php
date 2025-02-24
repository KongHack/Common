<?php
namespace GCWorld\Common;

use Exception;
use GCWorld\Common\Exceptions\ConfigInclusionException;
use GCWorld\Common\Exceptions\ConfigLoadException;
use GCWorld\Common\Exceptions\ConfigLocationException;
use GCWorld\Database\Controller;
use GCWorld\Database\Database;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Common
 * @package GCWorld\Common
 */
abstract class Common implements CommonInterface
{
    /**
     * Replace this in your extension to improve performance.
     * @var null|string
     */
    protected ?string $configPath = null;
    protected ?array  $config     = null;
    protected array   $caches     = [];
    protected array   $databases  = [];
    protected ?array  $filePaths  = null;
    protected ?array  $webPaths   = null;

    /**
     * If enabled, will cache yaml results to a .php file
     *
     * @var bool
     */
    protected bool $cacheConfig = false;

    /**
     * Common constructor.
     */
    protected function __construct()
    {
    }

    /**
     * @return static
     */
    final public static function getInstance(): static
    {
        static $instances = [];

        $calledClass = get_called_class();

        if (!isset($instances[$calledClass])) {
            $instances[$calledClass] = new $calledClass();
        }

        return $instances[$calledClass];
    }

    /**
     * @return void
     */
    protected function __clone()
    {
    }

    /**
     * Finds and loads the config file.
     * Please replace with direct path to your config file to prevent searching
     * @throws Exception
     * @return void
     */
    protected function loadConfig(): void
    {
        if ($this->configPath === null) {
            $this->configPath = $this->findConfigFile();
        }

        if(!str_ends_with($this->configPath,'.yml')) {
            throw new Exception('Common Config File must end in .yml');
        }

        if($this->cacheConfig) {
            $cacheFile = substr($this->configPath, 0, -3).'php';
            if(\file_exists($cacheFile)) {
                $this->config = require $cacheFile;
                $this->testConfig();
                return;
            }
        }

        $this->config = Yaml::parseFile($this->configPath);
        $this->testConfig();

        $this->processSort();
        $this->processIncludes();
        $this->processResolutions();

        if($this->cacheConfig) {
            $tmp               = $this->config;
            $tmp['GCINTERNAL'] = [
                'yaml_mtime' => filemtime($this->configPath),
                'cache_time' => time(),
            ];

            // Export to cache file
            $contents = '<?php'.PHP_EOL.PHP_EOL.'return '.\var_export($tmp, true).';'.PHP_EOL;
            \file_put_contents($cacheFile, $contents);
        }
    }

    /**
     * @return void
     */
    protected function processResolutions(): void
    {
        if(!isset($this->config['common']) || !isset($this->config['common']['resolve_hosts'])) {
            return;
        }
        if(!$this->config['common']['resolve_hosts']) {
            return;
        }

        array_walk_recursive($this->config, function(&$item, $key) {
            if($key !== 'host') {
                return;
            }

            $tmp = explode(':',$item);
            if(count($tmp) > 2) {
                return;
            }
            $tmp[0] = gethostbyname($tmp[0]);
            $item   = implode(':',$tmp);
        });
    }

    /**
     * @return void
     * @throws ConfigLoadException
     */
    protected function testConfig(): void
    {
        if (!is_array($this->config) || empty($this->config)) {
            throw new ConfigLoadException('Config File Failed to Load: '.$this->configPath);
        }
    }

    /**
     * @return void
     * @throws ConfigInclusionException
     */
    protected function processIncludes(): void
    {
        if(!isset($this->config['includes']) || !is_array($this->config['includes'])) {
            return;
        }
        $base = str_replace('config.yml','',$this->config);
        foreach($this->config['includes'] as $file) {
            if(!file_exists($base.$file)) {
                throw new ConfigInclusionException('Config Inclusion File Not Found. '.$file);
            }

            $items = Yaml::parseFile($this->configPath);
            $this->config = array_replace_recursive($this->config, $items);
        }

        $this->testConfig();
    }

    /**
     * @return void
     */
    protected function processSort(): void
    {
        if(!isset($this->config['common']) || !isset($this->config['common']['sort'])) {
            return;
        }
        if(!$this->config['common']['sort']) {
            return;
        }
        // Disable once sorted
        $this->config['common']['sort'] = false;

        $sort = function(&$arr) use (&$sort) {
            if(is_array($arr)) {
                ksort($arr);
                array_walk($arr, $sort);
            }
        };

        $sort($this->config);
        file_put_contents($this->configPath, Yaml::dump($this->config, 4));
    }

    /**
     * @return string
     * @throws ConfigLocationException
     */
    protected function findConfigFile(): string
    {
        // Check for our yml file.  If found, awesome
        $basePath = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $fileName = 'config'.DIRECTORY_SEPARATOR.'config.yml';
        $inc      = 0;
        $path     = $basePath;
        while(!file_exists($path.$fileName) && $inc < 12) {
            $path .= '..'.DIRECTORY_SEPARATOR;
            ++$inc;
        }

        if(!file_exists($path.$fileName)) {
            throw new ConfigLocationException('Common could not find config file');
        }

        return $this->configPath;
    }

    /**
     * @param string $heading
     * @return array
     * @throws Exception
     */
    public function getConfig(string $heading): array
    {
        if ($this->config == null) {
            $this->loadConfig();
        }

        return $this->config[$heading] ?? [];
    }

    /**
     * @param mixed $instance
     * @return DatabaseInterface
     * @throws Exception
     */
    public function getDatabase($instance = 'default'): DatabaseInterface
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!isset($this->databases[$instance])) {
            $databases = $this->getConfig('database');
            if (!array_key_exists($instance, $databases)) {
                throw new Exception('DB Config Not Found!');
            }
            $databaseArray = $databases[$instance];
            if(isset($databaseArray['alias']) && '' != $databaseArray['alias']) {
                $this->databases[$instance] = $this->getDatabase($databaseArray['alias']);
            }

            // Implement controller!
            if (isset($databaseArray['controller']) && $databaseArray['controller']) {
                $controller                 = Controller::getInstance($instance);
                $this->databases[$instance] = $controller->getDatabase(Controller::IDENTIFIER_READ);
            } else {
                $options = [];
                if(isset($databaseArray['ssl_key'])) {
                    $options[Database::MYSQL_ATTR_SSL_KEY] = $databaseArray['ssl_key'];
                }
                if(isset($databaseArray['ssl_cert'])) {
                    $options[Database::MYSQL_ATTR_SSL_CERT] = $databaseArray['ssl_cert'];
                }
                if(isset($databaseArray['ssl_ca'])) {
                    $options[Database::MYSQL_ATTR_SSL_CA] = $databaseArray['ssl_ca'];
                }
                if(isset($databaseArray['ssl_verify'])) {
                    $options[Database::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $databaseArray['ssl_verify'];
                }

                $database = new Database(
                    'mysql:charset=utf8mb4;host='.$databaseArray['host'].';dbname='.$databaseArray['name'].
                    (isset($databaseArray['port']) ? ';port='.$databaseArray['port'] : ''),
                    $databaseArray['user'],
                    $databaseArray['pass'],
                    $options
                );
                $database->setDefaults();
                $this->databases[$instance] = $database;
            }
        }

        return $this->databases[$instance];
    }

    /**
     * @param mixed $instance
     * @return \Redis|\RedisCluster|bool
     */
    public function getCache($instance = 'default')
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!class_exists('Redis')) {
            return false;
        }

        if (isset($this->caches[$instance])) {
            return $this->caches[$instance];
        }

        $caches = $this->getConfig('cache');
        if(!isset($caches[$instance])){
            return false;
        }
        $cacheArray = $caches[$instance];
        if (!is_array($cacheArray)) {
            return false;
        }
        set_error_handler('\\GCWorld\\ErrorHandlers\\ErrorHandlers::errorHandler');

        if(isset($cacheArray['cluster'])) {
            $cCluster = new \RedisCluster(
                $instance,
                $cacheArray['cluster'],
                $cacheArray['timeout'] ?? null,
                $cacheArray['readTimeout'] ?? null,
                $cacheArray['persistent'] ?? false,
                $cacheArray['auth'] ?? null
            );
            $this->caches[$instance] = $cCluster;
            restore_error_handler();

            return $cCluster;
        }

        try {
            $cache = new \Redis();
            if(isset($cacheArray['persistent']) && $cacheArray['persistent']) {
                $cache->pconnect($cacheArray['host'], $cacheArray['port']??6379);
            } else {
                $cache->connect($cacheArray['host'], $cacheArray['port']??6379);
            }
        } catch (\ErrorException) {
            $cache = false;
        }
        restore_error_handler();

        if($cache && isset($cacheArray['auth'])){
            $cache->auth($cacheArray['auth']);
        }

        $this->caches[$instance] = $cache;

        return $this->caches[$instance];
    }

    /**
     * @param string $instance
     * @return bool
     */
    public function closeDatabase(string $instance = 'default'): bool
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!isset($this->databases[$instance])) {
            return true;
        }

        $database = $this->databases[$instance];
        if ($database->getController() !== null) {
            $database->getController()->disconnectAll();
        } else {
            $database->disconnect();
        }

        unset($this->databases[$instance]);

        return true;
    }

    /**
     * @param string $key
     * @return string
     */
    public function getDirectory(string $key): string
    {
        if ($this->filePaths == null) {
            $paths           = $this->getConfig('paths');
            $this->filePaths = $paths['file'];
        }
        if (isset($this->filePaths[$key])) {
            return $this->filePaths[$key];
        }

        return '';
    }

    /**
     * @param string $key
     * @return string
     */
    public function getPath(string $key): string
    {
        if ($this->webPaths == null) {
            $paths          = $this->getConfig('paths');
            $web            = $paths['web'];
            $base           = $this->calculateBase($web['base']);
            $this->webPaths = [
                'base'        => $base,
                'temp'        => $base.$web['temp'],
                'asset_cache' => $base.$web['asset_cache']
            ];
        }
        if (isset($this->webPaths[$key])) {
            return $this->webPaths[$key];
        }

        return '';
    }

    /**
     * Note: This was designed to be dynamic.
     * It should ignore the default unless it's being run via cli or if HTTP_HOST is not being set
     * @param string $default
     * @return string
     */
    final protected function calculateBase(string $default = ''): string
    {
        // Get domain name/path so we can set up a base url
        if (isset($_SERVER['HTTP_HOST']) && php_sapi_name() != 'cli') {
            $base = $_SERVER['HTTP_HOST'];
        } else {
            return $default;
        }

        // Remove the WWW
        if (str_starts_with($base, 'www')) {
            $base = str_replace('www.', '', $base);
        }

        $sec = false;
        if (isset($_SERVER['HTTPS'])) {
            if ($_SERVER['HTTPS'] == "on") {
                $sec = true;
            }
        } elseif ($_SERVER['SERVER_PORT'] == 443) {
            $sec = true;
        }

        return ($sec ? 'https' : 'http').'://'.$base.'/';
    }
}
