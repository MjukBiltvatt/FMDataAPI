<?php

namespace INTERMediator\FileMakerServer\RESTAPI\PersistentSession;

use RuntimeException;

/**
 * ApcuSessionCache stores the authentication token used for persistent sessions by using APCu.
 *
 * APCu must be available in the current PHP environment. If you need another
 * storage backend, implement the SessionCacheInterface.
 *
 * @package INTER-Mediator\FileMakerServer\RESTAPI\PersistentSession
 * @link https://github.com/msyk/FMDataAPI GitHub Repository
 * @version 36
 */
class ApcuSessionCache implements SessionCacheInterface
{
    public function __construct()
    {
        if (!function_exists('apcu_fetch')) {
            throw new RuntimeException("APCu is required to use ApcuSessionCache.");
        }
    }

    /**
     * @param string $key Cache key.
     * @return string|false Returns the cached token, or false if the key doesn't exist.
     */
    public function get(string $key): string|false
    {
        $value = apcu_fetch($key);
        return is_string($value) ? $value : false;
    }

    /**
     * @param string $key Cache key.
     * @param string $value Session token.
     * @param int $ttl Time to live in seconds.
     */
    public function set(string $key, string $value, int $ttl): void
    {
        apcu_store($key, $value, $ttl);
    }

    /**
     * @param string $key Cache key.
     */
    public function delete(string $key): void
    {
        apcu_delete($key);
    }
}
