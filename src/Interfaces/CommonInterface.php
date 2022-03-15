<?php
namespace GCWorld\Common\Interfaces;

use GCWorld\Database\Interfaces\DatabaseInterface;

/**
 * Common Interface.
 */
interface CommonInterface
{
    /**
     * @param mixed $instance
     * @return DatabaseInterface
     */
    public function getDatabase($instance = 'default');

    /**
     * @param mixed $instance
     * @return \Redis
     */
    public function getCache($instance = 'default');

    /**
     * @param string $heading
     * @return array
     */
    public function getConfig(string $heading);

    /**
     * @param string $key
     * @return string
     */
    public function getDirectory(string $key);

    /**
     * @param string $key
     * @return string
     */
    public function getPath(string $key);
}
