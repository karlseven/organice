<?php
declare(strict_types=1);

namespace Core;

/**
 * Login brute-force throttling.
 *
 * Two independent counters over the same window:
 *
 *   by IP    — catches one attacker working through a password list
 *   by email — catches a distributed attempt where no single address stands out
 *
 * The email counter is the more important of the two and also the more
 * dangerous: on its own it is a denial-of-service tool, because anyone can lock
 * a known address out by failing against it. That is why a correct password
 * clears the account's failures (see sp_login_attempt_add) and why the lockout
 * is a few minutes rather than hours.
 */
final class Throttle
{
    private const WINDOW_MINUTES = 15;
    private const MAX_PER_IP     = 12;
    private const MAX_PER_EMAIL  = 6;

    /** Seconds to wait, or 0 if the attempt may proceed. */
    public static function retryAfter(string $email): int
    {
        $row = DB::procOne('sp_login_failures', [Ip::packed(), $email, self::WINDOW_MINUTES]);
        if (!$row) return 0;

        $blocked = (int)$row['by_ip']    >= self::MAX_PER_IP
                || (int)$row['by_email'] >= self::MAX_PER_EMAIL;

        return $blocked ? self::WINDOW_MINUTES * 60 : 0;
    }

    /**
     * A general per-address rate limit for endpoints that do real work.
     *
     * Keyed on the ADDRESS, not the session: an anonymous caller just drops the
     * cookie, so a session-keyed limit on a public endpoint counts nothing.
     *
     * Fails OPEN — if the counter itself errors, the request proceeds. A rate
     * limiter that takes the site down when its table is unavailable has turned
     * a denial-of-service defence into a denial of service.
     *
     * @param string $bucket  what is being limited, e.g. 'search'
     * @param int    $limit   requests allowed per window
     * @param int    $window  window length in seconds
     * @return bool true when the request may proceed
     */
    public static function allow(string $bucket, int $limit, int $window = 60): bool
    {
        try {
            $row = DB::procOne('sp_rate_hit', [$bucket, Ip::packed(), $window]);
            $hits = (int)($row['hits'] ?? 0);

            if (random_int(1, 100) === 1) DB::exec('sp_rate_prune', [$window * 10]);

            return $hits <= $limit;
        } catch (\Throwable $e) {
            error_log('rate limit unavailable: ' . $e->getMessage());
            return true;
        }
    }

    /** Refuse with a 429 and a Retry-After, or return. */
    public static function guard(string $bucket, int $limit, int $window = 60): void
    {
        if (self::allow($bucket, $limit, $window)) return;

        header('Retry-After: ' . $window);
        throw new HttpError(429, 'Too many requests. Slow down and try again shortly.');
    }

    public static function record(string $email, bool $ok): void
    {
        DB::exec('sp_login_attempt_add', [Ip::packed(), $email, $ok ? 1 : 0]);

        /* Pruning here rather than in a cron job: this is the only path that
           writes the table, the delete is bounded to 1000 rows, and running it
           on roughly one attempt in fifty keeps it off the hot path while still
           being far more often than the table needs it. */
        if (random_int(1, 50) === 1) {
            DB::exec('sp_login_attempts_prune');
        }
    }
}
