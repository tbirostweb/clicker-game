<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Leaderboard anti-abuse limits backed by symfony/rate-limiter (sliding
 * windows, filesystem cache + per-key flock: atomic across PHP workers of
 * the container). Limits are configured in config/packages/framework.yaml.
 *
 * Each method returns null when the request may proceed, or the number of
 * seconds to put in Retry-After.
 */
final class LeaderboardRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $leaderboardPostLimiter,
        private readonly RateLimiterFactoryInterface $leaderboardPostGlobalLimiter,
        private readonly RateLimiterFactoryInterface $leaderboardPutLimiter,
        private readonly RateLimiterFactoryInterface $leaderboardOwnerDeleteLimiter,
        private readonly RateLimiterFactoryInterface $leaderboardAdminFailureLimiter,
        private readonly RateLimiterFactoryInterface $leaderboardGetLimiter,
    ) {
    }

    public function post(Request $request): ?int
    {
        return $this->retryAfter($this->leaderboardPostLimiter->create(self::clientKey($request))->consume())
            ?? $this->retryAfter($this->leaderboardPostGlobalLimiter->create('global')->consume());
    }

    public function put(Request $request): ?int
    {
        return $this->retryAfter($this->leaderboardPutLimiter->create(self::clientKey($request))->consume());
    }

    public function ownerDelete(Request $request): ?int
    {
        return $this->retryAfter($this->leaderboardOwnerDeleteLimiter->create(self::clientKey($request))->consume());
    }

    public function get(Request $request): ?int
    {
        return $this->retryAfter($this->leaderboardGetLimiter->create(self::clientKey($request))->consume());
    }

    /** Admin lockout check: does not count a hit. */
    public function adminLockedOut(Request $request): ?int
    {
        $limit = $this->leaderboardAdminFailureLimiter->create(self::clientKey($request))->consume(0);

        return $limit->getRemainingTokens() > 0 ? null : $this->seconds($limit);
    }

    public function adminFailure(Request $request): void
    {
        $this->leaderboardAdminFailureLimiter->create(self::clientKey($request))->consume();
    }

    /**
     * Client key: the IP as resolved by Symfony (trusted proxies only), with
     * IPv6 addresses aggregated per /64 (one subscriber usually owns a whole
     * /64, so rotating inside it must not bypass the limits).
     */
    public static function clientKey(Request $request): string
    {
        $ip = (string) $request->getClientIp();
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if (false !== $packed && 16 === strlen($packed)) {
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
            }
        }

        return $ip;
    }

    private function retryAfter(RateLimit $limit): ?int
    {
        return $limit->isAccepted() ? null : $this->seconds($limit);
    }

    private function seconds(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
