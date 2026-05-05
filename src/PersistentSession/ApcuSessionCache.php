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
    /**
     * ApcuSessionCache constructor.
     * @throws RuntimeException If APCu is not available.
     */
    public function __construct()
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new RuntimeException("APCu is required to use ApcuSessionCache.");
        }
    }

    /**
     * @param string $key Cache key.
     * @return string|null Returns the cached token, or null if the key doesn't exist.
     */
    public function get(string $key): ?string
    {
        $value = apcu_fetch($key);
        return is_string($value) ? $value : null;
    }

    /**
     * @param string $key Cache key.
     * @param string $value Session token.
     * @param int $ttl Time to live in seconds.
     * @return bool Returns the result from the APCu store operation.
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        return apcu_store($key, $value, $ttl);
    }

    /**
     * @param string $key Cache key.
     * @return bool Returns the result from the APCu delete operation.
     */
    public function delete(string $key): bool
    {
        return apcu_delete($key);
    }
}
