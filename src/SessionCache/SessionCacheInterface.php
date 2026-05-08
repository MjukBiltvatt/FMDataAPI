<?php

declare(strict_types=1);

namespace INTERMediator\FileMakerServer\RESTAPI\SessionCache;

/**
 * Interface for session cache implementations.
 *
 * Implementations of this interface are used internally by the library to cache
 * FileMaker Data API session tokens. These tokens are sensitive credentials that
 * grant full access to the FileMaker Data API on behalf of the authenticated user.
 * Implementors must ensure that cached values are stored securely and are not
 * accessible to unauthorized parties.
 *
 * This interface should not be implemented directly. Instead, extend
 * {@see AbstractSessionCache}, which provides the cache key and TTL management
 * required by this interface, and implement the three cache methods.
 *
 * @see AbstractSessionCache
 */
interface SessionCacheInterface
{
    /**
     * Retrieves the cached FileMaker Data API session token for the current session.
     *
     * The cache key is managed internally by the library and set via
     * {@see AbstractSessionCache::setCacheKey()} prior to this method being called.
     *
     * @return string|null The cached session token, or null if no token exists
     *                     for the current key.
     */
    public function get(): ?string;

    /**
     * Persists a FileMaker Data API session token in the cache.
     *
     * The value being stored is a sensitive FileMaker Data API session token.
     * Implementors must ensure this value is stored securely and protected from
     * unauthorized access, as it grants full API access on behalf of the
     * authenticated user.
     *
     * The cache key and TTL are managed internally by the library and set via
     * {@see AbstractSessionCache::setCacheKey()} and {@see AbstractSessionCache::setTtl()}
     * prior to this method being called.
     *
     * @param string $value The FileMaker Data API session token to store.
     *                      This is a sensitive credential and must be treated as such.
     * @return bool True on success, false on failure.
     */
    public function set(string $value): bool;

    /**
     * Deletes the cached FileMaker Data API session token for the current session.
     *
     * The cache key is managed internally by the library and set via
     * {@see AbstractSessionCache::setCacheKey()} prior to this method being called.
     *
     * @return bool True on success, false if the key did not exist or deletion failed.
     */
    public function delete(): bool;
}
