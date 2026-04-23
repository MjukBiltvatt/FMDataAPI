<?php

namespace INTERMediator\FileMakerServer\RESTAPI\PersistentSession;

/**
 * SessionCacheInterface is the storage interface for the authentication token used by persistent sessions.
 *
 * Implement this interface if you want to keep the token in APCu or in any
 * other cache backend.
 *
 * @package INTER-Mediator\FileMakerServer\RESTAPI\PersistentSession
 * @link https://github.com/msyk/FMDataAPI GitHub Repository
 * @version 36
 */
interface SessionCacheInterface
{
    /**
     * Retrieve a cached token.
     * @param string $key Cache key.
     * @return string|false Returns the cached token, or false if the key doesn't exist.
     */
    public function get(string $key): string|false;

    /**
     * Store a token with a TTL in seconds.
     * @param string $key Cache key.
     * @param string $value Session token.
     * @param int $ttl Time to live in seconds.
     */
    public function set(string $key, string $value, int $ttl): void;

    /**
     * Delete a cached token.
     * @param string $key Cache key.
     */
    public function delete(string $key): void;
}
