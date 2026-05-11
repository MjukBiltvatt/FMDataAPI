<?php

namespace INTERMediator\FileMakerServer\RESTAPI\SessionCache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class Psr6SessionCache extends AbstractSessionCache
{
    private CacheItemPoolInterface $cache;

    public function __construct(CacheItemPoolInterface $cache) {
        parent::__construct();
        $this->cache = $cache;
    }

    public function get(): ?string
    {
        try {
            $item = $this->cache->getItem($this->cacheKey);
            if (!$item->isHit()) {
                return null;
            }
            $value = $item->get();
            return is_string($value) ? $value : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public function set(string $value): bool
    {
        try {
            $item = $this->cache->getItem($this->cacheKey);
            $item->set($value);
            $item->expiresAfter($this->ttl);
            return $this->cache->save($item);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function delete(): bool
    {
        try {
            return $this->cache->deleteItem($this->cacheKey);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }
}
