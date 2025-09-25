<?php
namespace GCWorld\Common;

use Exception;
use GCWorld\Common\Exceptions\ConfigInclusionException;
use GCWorld\Common\Exceptions\ConfigLoadException;
use GCWorld\Common\Exceptions\ConfigLocationException;
use Symfony\Component\Yaml\Yaml;

/**
 * CommonConfig Class
 */
class CommonConfig
{
    protected array  $config = [];
    protected string $configPath;

    /**
     * If enabled, will cache yaml results to a .php file
     *
     * @var bool
     */
    protected static bool $cacheConfig = true;


    /**
     * @param string|null $configPath
     */
    public function __construct(?string $configPath = null)
    {
        if(empty($configPath)) {
            $configPath = $this->findConfigFile();
        }
        $this->configPath = $configPath;
        $this->loadConfig();
    }

    /**
     * @return array
     */
    public function getArray(): array
    {
        return $this->config;
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function loadConfig(): void
    {
        if(!str_ends_with($this->configPath,'.yml')) {
            throw new Exception('Common Config File must end in .yml');
        }

        if(self::$cacheConfig) {
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

        if(self::$cacheConfig && isset($cacheFile)) {
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

        return $path.$fileName;
    }

    /**
     * @return void
     * @throws ConfigLoadException
     */
    protected function testConfig(): void
    {
        if (empty($this->config)) {
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

}