<?php
namespace GCWorld\Common;

use GCWorld\Database\Database;

abstract class Common implements \GCWorld\Interfaces\Common
{
    /**
     * Replace this in your extension to improve performance.
     * @var null|string
     */
    protected $configPath = null;

    protected $config     = null;
    protected $caches     = array();
    protected $databases  = array();
    protected $filePaths  = null;
    protected $webPaths   = null;


    protected function __construct()
    {
    }

    /**
     * @return mixed
     */
    final public static function getInstance()
    {
        static $instances = array();

        $calledClass = get_called_class();

        if (!isset($instances[$calledClass])) {
            $instances[$calledClass] = new $calledClass();
        }

        return $instances[$calledClass];
    }

    final private function __clone()
    {
    }

    /**
     * Finds and loads the config.ini file.
     * Please replace with direct path to your config file to prevent searching
     * @throws \Exception
     */
    protected function loadConfig()
    {
        if ($this->configPath == null) {
            $fileName = 'config'.DIRECTORY_SEPARATOR.'config.ini';
            $path = dirname(__FILE__).'..'.DIRECTORY_SEPARATOR;
            $i = 0;
            while (!file_exists($path.$fileName)) {
                $path .= '..'.DIRECTORY_SEPARATOR;
                if ($i > 6) {
                    throw new \Exception('config.ini file not found');
                }
            }
            $this->configPath = $path.$fileName;
        }
        $this->config = $this->parse_ini_file_multi($this->configPath, true);
        if (!is_array($this->config) || count($this->config) < 1) {
            throw new \Exception('Config File Failed to Load: '.$this->configPath);
        }
    }

    /**
     * @param $heading
     * @return array
     * @throws \Exception
     */
    public function getConfig($heading)
    {
        if ($this->config == null) {
            $this->loadConfig();
        }
        return $this->config[$heading];
    }

    /**
     * @param string $instance
     * @return \GCWorld\Database\Database
     */
    public function getDatabase($instance = 'default')
    {
        if (!isset($this->databases[$instance])) {
            $databases = $this->getConfig('database');
            $databaseArray = $databases[$instance];

            $this->databases[$instance] = new Database(
                'mysql:host='.$databaseArray['host'].';dbname='.$databaseArray['name'],
                $databaseArray['user'],
                $databaseArray['pass'],
                array(Database::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8')
            );
        }
        return $this->databases[$instance];
    }

    /**
     * @param string $instance
     * @return \Redis
     */
    public function getCache($instance = 'default')
    {
        if (!isset($this->caches[$instance])) {
            $caches = $this->getConfig('cache');
            $cacheArray = $caches[$instance];
            $cache = new \Redis();
            $cache->connect($cacheArray['host']);
            $this->caches[$instance] = $cache;
        }
        return $this->caches[$instance];
    }

    /**
     * @param $key
     * @return string
     */
    public function getDirectory($key)
    {
        if ($this->filePaths == null) {
            $paths = $this->getConfig('paths');
            $this->filePaths = $paths['file'];
        }
        return $this->filePaths[$key];
    }

    /**
     * @param $key
     * @return string
     */
    public function getPath($key)
    {
        if ($this->webPaths == null) {
            $paths = $this->getConfig('paths');
            $web = $paths['web'];
            $base = $this->calculateBase($web['base']);
            $this->webPaths = array(
                'base'  => $base,
                'temp'  => $base.$web['temp'],
                'asset_cache' => $base.$web['asset_cache']
            );
        }
        return $this->webPaths[$key];
    }

    /**
     * @param string $default
     * @return string
     */
    final protected function calculateBase($default = '')
    {
        $base = '';

        // Get domain name/path so we can set up a base url
        if (isset($_SERVER['HTTP_HOST'])) {
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

        return ($sec?'https':'http').'://'.$base.'/';
    }

    /**
     * @param      $file
     * @param bool $process_sections
     * @param int  $scanner_mode
     * @return array
     */
    function parse_ini_file_multi($file, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL)
    {
        $explode_str = '.';
        $escape_char = "'";
        // load ini file the normal way
        $data = parse_ini_file($file, $process_sections, $scanner_mode);
        if (!$process_sections) {
            $data = array($data);
        }
        foreach ($data as $section_key => $section) {
            // loop inside the section
            foreach ($section as $key => $value) {
                if (strpos($key, $explode_str)) {
                    if (substr($key, 0, 1) !== $escape_char) {
                        // key has a dot. Explode on it, then parse each sub key
                        // and set value at the right place thanks to references
                        $sub_keys = explode($explode_str, $key);
                        $subs =& $data[$section_key];
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
                    } // we have escaped the key, so we keep dots as they are
                    else {
                        $new_key = trim($key, $escape_char);
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
