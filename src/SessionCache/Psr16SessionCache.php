<?php

namespace INTERMediator\FileMakerServer\RESTAPI\SessionCache;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class Psr16SessionCache extends AbstractSessionCache
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache) {
        parent::__construct();
        $this->cache = $cache;
    }

    public function get(): ?string
    {
        try {
            $value = $this->cache->get($this->cacheKey);
            return is_string($value) ? $value : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public function set(string $value): bool
    {
        try {
            return $this->cache->set($this->cacheKey, $value, $this->ttl);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function delete(): bool
    {
        try {
            return $this->cache->delete($this->cacheKey);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }
}
