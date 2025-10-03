<?php
namespace GCWorld\Common;

use Exception;
use GCWorld\Database\Controller;
use GCWorld\Database\Database;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PDO;

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
     * @param string $heading
     * @return array
     * @throws Exception
     */
    public function getConfig(string $heading): array
    {
        if ($this->config == null) {
            $cConfig      = new CommonConfig($this->configPath);
            $this->config = $cConfig->getArray();
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
                    $options[PDO::MYSQL_ATTR_SSL_KEY] = $databaseArray['ssl_key'];
                }
                if(isset($databaseArray['ssl_cert'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CERT] = $databaseArray['ssl_cert'];
                }
                if(isset($databaseArray['ssl_ca'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $databaseArray['ssl_ca'];
                }
                if(isset($databaseArray['ssl_verify'])) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $databaseArray['ssl_verify'];
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
     * @param string $instance
     *
     * @return bool|\RedisCluster|\Redis
     */
    public function getCache(string $instance = 'default'): bool|\RedisCluster|\Redis
    {
        if (!class_exists('Redis')) {
            return false;
        }
        $instance   = (empty($instance) ? 'default' : $instance);
        $tmp        = explode(':',$instance);
        $instance   = $tmp[0];
        $identifier = isset($tmp[1])?':'.$tmp[1]:'';

        if (isset($this->caches[$instance.$identifier])) {
            return $this->caches[$instance.$identifier];
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
            $this->caches[$instance.$identifier] = $cCluster;
            restore_error_handler();

            return $cCluster;
        }

        try {
            $cache = new \Redis();
            if(isset($cacheArray['persistent']) && $cacheArray['persistent']) {
                $cache->pconnect(
                    $cacheArray['host'],
                    $cacheArray['port'] ?? 6379,
                    $cacheArray['timeout'] ?? 0,
                    'redis'.(empty($identifier)?':'.getmypid():$identifier)
                );
            } else {
                $cache->connect($cacheArray['host'], $cacheArray['port'] ?? 6379);
            }
        } catch (\ErrorException) {
            $cache = false;
        }
        restore_error_handler();

        if($cache && isset($cacheArray['auth'])){
            $cache->auth($cacheArray['auth']);
        }

        $this->caches[$instance.$identifier] = $cache;

        return $this->caches[$instance.$identifier];
    }

    /**
     * @param string $instance
     * @return void
     */
    public function closeDatabase(string $instance = 'default'): void
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!isset($this->databases[$instance])) {
            return;
        }

        $database = $this->databases[$instance];
        if ($database->getController() !== null) {
            $database->getController()->disconnectAll();
        } else {
            $database->disconnect();
        }

        unset($this->databases[$instance]);

        return;
    }

    /**
     * @param string $instance
     */
    public function closeCache(string $instance = 'default'): void
    {
        $instance   = (empty($instance) ? 'default' : $instance);
        $tmp        = explode(':',$instance);
        $instance   = $tmp[0];
        $identifier = isset($tmp[1])?':'.$tmp[1]:'';


        if (!isset($this->caches[$instance.$identifier])) {
            return;
        }

        $database = $this->caches[$instance.$identifier];
        if ($database->getController() !== null) {
            $database->getController()->disconnectAll();
        } else {
            $database->disconnect();
        }

        unset($this->caches[$instance.$identifier]);
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
