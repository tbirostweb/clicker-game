<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minimal fixed-window rate limiter backed by the application cache pool
 * (no extra dependency). Best effort, per container: it is an anti-abuse
 * brake, not a hard quota. The key should identify the client (IP, token...).
 */
final class SimpleRateLimiter
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * True when $key already used its $limit hits in the current window
     * (does not count a hit).
     */
    public function isLimited(string $key, int $limit, int $windowSeconds): bool
    {
        $item = $this->cache->getItem($this->itemKey($key, $windowSeconds));

        return $item->isHit() && (int) $item->get() >= $limit;
    }

    /**
     * Counts one hit for $key and returns false once $limit hits happened
     * within the current $windowSeconds window.
     */
    public function consume(string $key, int $limit, int $windowSeconds): bool
    {
        $item = $this->cache->getItem($this->itemKey($key, $windowSeconds));
        $hits = $item->isHit() ? (int) $item->get() : 0;

        if ($hits >= $limit) {
            return false;
        }

        $item->set($hits + 1);
        $item->expiresAfter($windowSeconds);
        $this->cache->save($item);

        return true;
    }

    private function itemKey(string $key, int $windowSeconds): string
    {
        $window = intdiv(time(), $windowSeconds);

        return 'rl_' . hash('sha256', $key . '|' . $windowSeconds . '|' . $window);
    }
}
