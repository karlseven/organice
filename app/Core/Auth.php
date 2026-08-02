<?php
declare(strict_types=1);

namespace Core;

/**
 * Session-backed sign-in. There is no public registration: accounts are made
 * in /admin, which is what keeps a self-hosted docs site from becoming an open
 * wiki by accident.
 */
final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password): bool
    {
        $row = DB::procOne('sp_user_by_email', [$email]);
        /* The hash comparison runs even when no such user exists, so a missing
           account and a wrong password take the same time to answer — email
           addresses cannot be enumerated from the login form's latency. */
        $hash = $row['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok = password_verify($password, $hash);

        if (!$ok || !$row || (int)$row['is_active'] !== 1) {
            Throttle::record($email, false);
            Audit::log('login.failed', 'user', (int)($row['id'] ?? 0), $email);
            return false;
        }

        /* Regenerated BEFORE the id is written, so a session identifier an
           attacker planted in the browser beforehand is not the one that ends
           up authenticated. */
        session_regenerate_id(true);
        $_SESSION['uid']      = (int)$row['id'];
        $_SESSION['started']  = time();
        $_SESSION['seen']     = time();
        /* Bound to the user agent. Not a strong control on its own — a header
           is forgeable — but a stolen cookie replayed from a different client
           is the common case, and this costs one string comparison. */
        $_SESSION['ua']       = self::uaFingerprint();

        DB::exec('sp_user_touch_login', [(int)$row['id']]);
        Throttle::record($email, true);
        self::$user = null;
        Audit::log('login.ok', 'user', (int)$row['id'], $email);
        return true;
    }

    /** Sessions expire on idle and, separately, on absolute age. */
    private const IDLE_SECONDS     = 8 * 3600;
    private const ABSOLUTE_SECONDS = 30 * 86400;

    private static function uaFingerprint(): string
    {
        return substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 32);
    }

    /**
     * True when this session should no longer be trusted.
     *
     * The absolute limit matters independently of the idle one: without it, a
     * session that is touched daily never expires at all, and a cookie stolen
     * once is valid forever.
     */
    private static function expired(): bool
    {
        $now     = time();
        $started = (int)($_SESSION['started'] ?? 0);
        $seen    = (int)($_SESSION['seen'] ?? 0);

        if ($started === 0 || $seen === 0) return true;
        if ($now - $seen    > self::IDLE_SECONDS)     return true;
        if ($now - $started > self::ABSOLUTE_SECONDS) return true;
        if (($_SESSION['ua'] ?? '') !== self::uaFingerprint()) return true;

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        /* The cookie has to be expired explicitly. session_destroy() drops the
           server-side data but leaves the browser holding the identifier, which
           then gets reused for the next session on this machine. */
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        self::$user = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** The signed-in user row, or null. Cached for the request. */
    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $uid = (int)($_SESSION['uid'] ?? 0);
        if ($uid <= 0) return null;

        if (self::expired()) {
            self::logout();
            return null;
        }
        $_SESSION['seen'] = time();

        $row = DB::procOne('sp_user_by_id', [$uid]);
        /* A deactivated account keeps its session cookie until it expires; the
           check has to happen on every request, not only at sign-in. */
        if (!$row || (int)$row['is_active'] !== 1) {
            unset($_SESSION['uid']);
            return null;
        }
        return self::$user = $row;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
