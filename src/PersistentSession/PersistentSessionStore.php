<?php

namespace INTERMediator\FileMakerServer\RESTAPI\PersistentSession;

/**
 * PersistentSessionStore stores and retrieves the cached session token used for persistent sessions.
 *
 * The cache key is built from the database name, user name, and an optional scope value.
 *
 * @package INTER-Mediator\FileMakerServer\RESTAPI\PersistentSession
 * @link https://github.com/msyk/FMDataAPI GitHub Repository
 * @version 36
 */
class PersistentSessionStore
{
    /**
     * TTL of the cached session token in seconds.
     * This value is slightly shorter than the expected server-side session lifetime.
     *
     * @var int
     * @ignore
     */
    private const TOKEN_TTL = 840;

    /**
     * @var SessionCacheInterface Cache backend for persistent sessions.
     * @ignore
     */
    private SessionCacheInterface $cache;
    /**
     * @var string Database name.
     * @ignore
     */
    private string $database;
    /**
     * @var string User name.
     * @ignore
     */
    private string $user;
    /**
     * @var string Scope of the session token.
     * @ignore
     */
    private string $scope;

    /**
     * @param SessionCacheInterface $cache Cache backend.
     * @param string $database Database name.
     * @param string $user User name.
     * @param string $scope Optional scope used to distinguish tokens for different hosts or environments.
     */
    public function __construct(
        SessionCacheInterface $cache,
        string                $database,
        string                $user,
        string                $scope = '')
    {
        $this->cache = $cache;
        $this->database = $database;
        $this->user = $user;
        $this->scope = $scope;
    }

    /**
     * Retrieve a cached token.
     * @return string|null Returns the cached token, or null if the key doesn't exist.
     */
    public function get(): ?string
    {
        return $this->cache->get($this->cacheKey());
    }

    /**
     * Cache the current session token.
     * @param string $token The session token.
     * @return bool|null Returns the result from the cache backend.
     */
    public function set(string $token): ?bool
    {
        return $this->cache->set($this->cacheKey(), $token, self::TOKEN_TTL);
    }

    /**
     * Clear the cached session token.
     * @return bool|null Returns the result from the cache backend.
     */
    public function clear(): ?bool
    {
        return $this->cache->delete($this->cacheKey());
    }

    /**
     * Build the cache key from the database name, user name, and scope.
     * @return string
     */
    private function cacheKey(): string
    {
        return 'fm_token:' . hash('sha256', json_encode([
            'scope' => $this->scope,
            'database' => $this->database,
            'user' => $this->user,
        ]));
    }
}
