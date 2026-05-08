<?php

declare(strict_types=1);

namespace INTERMediator\FileMakerServer\RESTAPI\SessionCache;

/**
 * Base class for session cache implementations.
 *
 * Provides the cache key and TTL to concrete implementations, both of which
 * are managed internally by the library. The cache key and TTL will not change
 * during a single PHP request.
 *
 * As this cache stores FileMaker Data API session tokens, which are sensitive
 * credentials granting full API access on behalf of the authenticated user,
 * implementors must ensure that the underlying cache storage is secure and
 * not accessible to unauthorized parties.
 *
 * To provide a custom cache backend, extend this class and implement
 * {@see SessionCacheInterface::get()}, {@see SessionCacheInterface::set()},
 * and {@see SessionCacheInterface::delete()}, using {@see self::$cacheKey}
 * and {@see self::$ttl} in your implementations.
 *
 * Example:
 *
 *     class RedisSessionCache extends AbstractSessionCache
 *     {
 *         public function get(): ?string
 *         {
 *             return $this->redis->get($this->cacheKey) ?? null;
 *         }
 *
 *         public function set(string $value): bool
 *         {
 *             return $this->redis->setex($this->cacheKey, $this->ttl, $value);
 *         }
 *
 *         public function delete(): bool
 *         {
 *             return $this->redis->del($this->cacheKey) > 0;
 *         }
 *     }
 *
 * @see SessionCacheInterface
 */
abstract class AbstractSessionCache implements SessionCacheInterface
{
    /**
     * The cache key for the current session.
     *
     * Always set by the library via {@see self::setCacheKey()} before any cache
     * operation is performed. Will not change during a single PHP request.
     * Implementing classes should use this property directly in their
     * {@see SessionCacheInterface::get()}, {@see SessionCacheInterface::set()},
     * and {@see SessionCacheInterface::delete()} implementations.
     */
    protected string $cacheKey;

    /**
     * The time-to-live in seconds for cached session tokens.
     *
     * Set by the library via {@see self::setTtl()} before any cache operation
     * is performed, defaulting to the value provided at construction time.
     * Will not change during a single PHP request. Implementing classes should
     * use this property directly in their {@see SessionCacheInterface::set()} implementation.
     *
     */
    protected int $ttl;

    /**
     * @param int $defaultTtl Default time-to-live in seconds for cached session tokens.
     *                        Defaults to 840 seconds (14 minutes), reflecting the
     *                        default FileMaker Data API session timeout. Adjust this
     *                        value if your FileMaker Server is configured with a
     *                        different session timeout.
     */
    public function __construct(int $defaultTtl = 840)
    {
        $this->ttl = $defaultTtl;
    }

    /**
     * Sets the cache key for the current session.
     *
     * This method is called internally by the library and should not be called
     * manually. The key will not change during a single PHP request.
     *
     * @param string $key The cache key to use for subsequent cache operations.
     */
    final public function setCacheKey(string $key): void
    {
        $this->cacheKey = $key;
    }

    /**
     * Sets the time-to-live for cached session tokens.
     *
     * This method is called internally by the library and should not be called
     * manually. The TTL will not change during a single PHP request.
     *
     * @param int $ttl Time-to-live in seconds for the cached session token.
     */
    final public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }
}
