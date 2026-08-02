<?php
declare(strict_types=1);

namespace Core;

/**
 * Session storage in MySQL rather than PHP's default files.
 *
 * The reason is plain: the file handler keeps sessions on one machine's disk,
 * so the moment there are two web servers a reader signs in on one and is
 * anonymous on the other. It also makes "sign this user out everywhere"
 * possible, which a password change ought to do.
 *
 * It runs on its OWN database connection (DB::sessionPdo). That is essential:
 * the lock below is held for the whole request, and on a shared connection that
 * transaction would enclose every application query too — so releasing the
 * session lock would roll back the page the request just saved.
 *
 * The subtle part is LOCKING. PHP's file handler holds an exclusive lock on the
 * session file from read to write, which serialises concurrent requests
 * carrying the same cookie. This handler does the same with a row lock inside a
 * transaction. Without it, the editor's overlapping preview/save/history
 * requests each read the same session, each modify their own copy, and the last
 * write wins — silently discarding a rotated CSRF token or a flash message.
 * A database handler that skips the lock is a regression, not a simplification.
 */
final class Session implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** Rows older than this are dead. Matches Auth's idle timeout. */
    private const MAX_AGE = 8 * 3600;

    private bool $inTransaction = false;

    public static function install(): void
    {
        $h = new self();
        session_set_save_handler($h, true);

        /* Last line of defence. If the request dies somewhere PHP's session
           shutdown cannot reach — a fatal error, or an exit inside a nested
           output buffer — this still releases the row lock. Without it one bad
           request stalls every subsequent one for MySQL's lock-wait timeout. */
        register_shutdown_function(static function () use ($h): void {
            $h->release();
        });
    }

    /** Roll back rather than commit: a request that died has no result worth keeping. */
    private function release(): void
    {
        if (!$this->inTransaction) return;
        try {
            $pdo = DB::sessionPdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (\Throwable $e) {
            error_log('session lock release failed: ' . $e->getMessage());
        }
        $this->inTransaction = false;
    }

    /*
     * The six SessionHandlerInterface methods below carry NO parameter or
     * return types, deliberately.
     *
     * PHP 7.4's copy of that interface declares none, so a typed override is
     * "Declaration must be compatible with SessionHandlerInterface::open(...)"
     * — a fatal at class-load time, on every single request. It is a nasty one
     * to spot: the failure happens after headers are sent, so the response is
     * still 200 and only the body shows the error.
     *
     * #[\ReturnTypeWillChange] is an attribute on PHP 8 and an ordinary #
     * comment on 7.4, so the one line serves both: 7.4 ignores it, and 8.1+
     * stops deprecating the missing return type.
     *
     * @param string $path
     * @param string $name
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function open($path, $name)
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        $pdo = DB::sessionPdo();

        /* Take ownership unconditionally.
         *
         * Core\DB holds ONE PDO instance for the process, and under a
         * single-process SAPI (php -S, or any setup with persistent
         * connections) that instance outlives the request. An earlier request
         * that somehow ended without committing would leave a transaction — and
         * therefore a row lock — open on this connection.
         *
         * Skipping beginTransaction() in that case, as an `if (!inTransaction)`
         * guard does, is the worst possible response: this handler then never
         * sets its own flag, so it never commits either, and the stale lock is
         * held forever. Every later request blocks on it until MySQL's 50-second
         * lock timeout. Closing whatever was left open first makes the leak
         * self-healing instead of permanent.
         */
        if ($pdo->inTransaction()) {
            try { $pdo->commit(); } catch (\Throwable $e) { /* already gone */ }
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;

        $row = DB::sessionProcOne('sp_session_read', [$id, self::MAX_AGE]);
        return (string)($row['data'] ?? '');
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data)
    {
        /* user_id is denormalised onto the row so sessions can be revoked per
           user without deserialising every blob to find out whose it is. */
        $uid = (int)($_SESSION['uid'] ?? 0);

        DB::sessionExec('sp_session_write', [$id, $data, $uid, Ip::packed()]);
        $this->commit();
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close()
    {
        // a request that read but never wrote still has to release the lock
        $this->commit();
        return true;
    }

    #[\ReturnTypeWillChange]
    public function destroy($id)
    {
        DB::sessionExec('sp_session_destroy', [$id]);
        $this->commit();
        return true;
    }

    /** PHP calls this instead of write() when the data is unchanged. */
    #[\ReturnTypeWillChange]
    public function updateTimestamp($id, $data)
    {
        return $this->write($id, $data);
    }

    #[\ReturnTypeWillChange]
    public function validateId($id)
    {
        /* Refusing an id we do not hold is what makes session.use_strict_mode
           effective — otherwise an attacker can plant an id of their choosing
           in the victim's browser and have it become a real session. */
        return preg_match('/^[A-Za-z0-9,-]{22,128}$/', $id) === 1;
    }

    /** @return int|false */
    #[\ReturnTypeWillChange]
    public function gc($maxLifetime)
    {
        DB::sessionExec('sp_session_gc', [max($maxLifetime, self::MAX_AGE)]);
        return true;
    }

    private function commit(): void
    {
        if (!$this->inTransaction) return;
        $pdo = DB::sessionPdo();
        if ($pdo->inTransaction()) $pdo->commit();
        $this->inTransaction = false;
    }
}
