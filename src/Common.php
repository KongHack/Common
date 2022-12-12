<?php
namespace GCWorld\Common;

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
     * Finds and loads the config file.  Will also convert from ini to yml
     * Please replace with direct path to your config file to prevent searching
     * @throws \Exception
     * @return void
     */
    protected function loadConfig()
    {
        if ($this->configPath === null) {
            $this->configPath = $this->findConfigFile();
        }

        if(!str_ends_with($this->configPath,'.yml')) {
            throw new \Exception('Common Config File must end in .yml');
        }

        if($this->cacheConfig) {
            $cacheFile = substr($this->configPath,-3).'php';
            if(file_exists($cacheFile)) {
                $this->config = require $cacheFile;
                return;
            }
        }
        $this->config = Yaml::parseFile($this->configPath);
        if($this->cacheConfig) {
            $contents = '<?php'.PHP_EOL.PHP_EOL.'return '.\var_export($this->config, true).';'.PHP_EOL;
            \file_put_contents($cacheFile, $contents);
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function findConfigFile()
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
            throw new \Exception('Common could not find config file');
        }

        return $this->configPath;
    }

    /**
     * @param string $heading
     * @return array
     * @throws \Exception
     */
    public function getConfig(string $heading)
    {
        if ($this->config == null) {
            $this->loadConfig();
            if (!is_array($this->config) || count($this->config) < 1) {
                throw new \Exception('Config File Failed to Load: '.$this->configPath);
            }
        }
        if (array_key_exists($heading, $this->config)) {
            return $this->config[$heading];
        }
        return [];
    }

    /**
     * @param mixed $instance
     * @return DatabaseInterface
     * @throws \Exception
     */
    public function getDatabase($instance = 'default')
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!isset($this->databases[$instance])) {
            $databases = $this->getConfig('database');
            if (!array_key_exists($instance, $databases)) {
                throw new \Exception('DB Config Not Found!');
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
     * @return \Redis|bool
     */
    public function getCache($instance = 'default')
    {
        $instance = (empty($instance) ? 'default' : $instance);

        if (!class_exists('Redis')) {
            return false;
        }

        if (!isset($this->caches[$instance])) {
            $caches = $this->getConfig('cache');
            if (!is_array($caches)) {
                return false;
            }
            if (!array_key_exists($instance, $caches)) {
                return false;
            }
            $cacheArray = $caches[$instance];
            if (!is_array($cacheArray)) {
                return false;
            }
            set_error_handler('\GCWorld\ErrorHandlers\ErrorHandlers::errorHandler');
            try {
                $cache = new \Redis();
                $cache->connect($cacheArray['host']);
            } catch (\ErrorException $e) {
                $cache = false;
            }
            restore_error_handler();

            if($cache && array_key_exists('auth', $cacheArray)){
                $cache->auth($cacheArray['auth']);
            }


            $this->caches[$instance] = $cache;
        }

        return $this->caches[$instance];
    }

    /**
     * @param string $instance
     * @return bool
     */
    public function closeDatabase(string $instance = 'default')
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
    public function getDirectory(string $key)
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
    public function getPath(string $key)
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
     * It should ignore the default unless it's being ran via cli or if HTTP_HOST is not being set
     * @param string $default
     * @return string
     */
    final protected function calculateBase(string $default = '')
    {
        // Get domain name/path so we can set up a base url
        if (isset($_SERVER['HTTP_HOST']) && php_sapi_name() != 'cli') {
            $base = $_SERVER['HTTP_HOST'];
        } else {
            return $default;
        }

        // Remove the WWW
        if (strstr($base, 'www')) {
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

    /**
     * NOTE: Will be removed in a future release
     *
     * @param string $file
     * @param bool   $process_sections
     * @param int    $scanner_mode
     * @return array
     */
    public static function parse_ini_file_multi(string $file, bool $process_sections = false, int $scanner_mode = INI_SCANNER_NORMAL)
    {
        $explode_str = '.';
        $escape_char = "'";
        // load ini file the normal way
        $data = parse_ini_file($file, $process_sections, $scanner_mode);
        if (!$process_sections) {
            $data = [$data];
        }
        foreach ($data as $section_key => $section) {
            // loop inside the section
            foreach ($section as $key => $value) {
                if (strpos($key, $explode_str)) {
                    if (substr($key, 0, 1) !== $escape_char) {
                        // key has a dot. Explode on it, then parse each sub key
                        // and set value at the right place thanks to references
                        $sub_keys = explode($explode_str, $key);
                        $subs     =& $data[$section_key];
                        foreach ($sub_keys as $sub_key) {
                            if (!isset($subs[$sub_key])) {
                                $subs[$sub_key] = [];
                            }
                            $subs =& $subs[$sub_key];
                        }
                        // set the value at the right place
                        $subs = $value;
                        // unset the dotted key, we don't need it anymore
                        unset($data[$section_key][$key]);
                    } else {
                        $new_key                      = trim($key, $escape_char);
                        $data[$section_key][$new_key] = $value;
                        unset($data[$section_key][$key]);
                    }
                }
            }
        }
        if (!$process_sections) {
            $data = $data[0];
        }

        return $data;
    }
}
