<?php
declare(strict_types=1);

namespace Core;

final class Ip
{
    /**
     * The client address, packed for VARBINARY(16).
     *
     * REMOTE_ADDR only. X-Forwarded-For is not consulted, because a header the
     * client controls is a header the client can forge — trusting it here would
     * let one attacker present a fresh "address" on every request and walk
     * straight through the login throttle.
     *
     * If this ever runs behind a reverse proxy, the fix is to have the PROXY
     * overwrite REMOTE_ADDR (or to check X-Forwarded-For only when
     * REMOTE_ADDR is the known proxy), not to read the header unconditionally.
     */
    public static function packed(): string
    {
        $raw = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $bin = @inet_pton($raw);
        // an unparseable address still needs a stable, non-empty bucket
        return $bin === false ? str_repeat("\0", 16) : $bin;
    }

    public static function text(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
