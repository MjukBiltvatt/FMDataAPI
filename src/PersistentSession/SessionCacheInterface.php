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
     * @return string|null Returns the cached token, or null if the key doesn't exist.
     */
    public function get(string $key): ?string;

    /**
     * Store a token with a TTL in seconds.
     * @param string $key Cache key.
     * @param string $value Session token.
     * @param int $ttl Time to live in seconds.
     * @return bool|null Returns true on success, false on failure, or null if the backend doesn't provide a result.
     */
    public function set(string $key, string $value, int $ttl): ?bool;

    /**
     * Delete a cached token.
     * @param string $key Cache key.
     * @return bool|null Returns true on success, false on failure, or null if the backend doesn't provide a result.
     */
    public function delete(string $key): ?bool;
}
