<?php
declare(strict_types=1);

/**
 * Change an account's email and/or password from the command line.
 *
 * This exists because there is no password-reset flow and `/admin/users`
 * deliberately refuses to let an admin edit their own account — that guard is
 * what stops the last admin locking themselves out, but it also means the only
 * way to change your own credentials is here.
 *
 * Usage:
 *   php scripts/set-credentials.php <current-email> [--email=new@example.com] [--password=secret]
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line.\n");
}

require dirname(__DIR__) . '/config/config.php';

use Core\Auth;
use Core\DB;
use Core\Ip;

$args     = array_slice($argv, 1);
$who      = $args[0] ?? '';
$newEmail = null;
$newPass  = null;

foreach ($args as $a) {
    if (str_starts_with($a, '--email='))    $newEmail = substr($a, 8);
    if (str_starts_with($a, '--password=')) $newPass  = substr($a, 11);
}

if ($who === '' || ($newEmail === null && $newPass === null)) {
    exit("Usage: php scripts/set-credentials.php <current-email> [--email=…] [--password=…]\n");
}

$user = DB::procOne('sp_user_by_email', [$who]);
if (!$user) exit("No account with that email.\n");

$id = (int)$user['id'];

if ($newEmail !== null) {
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) exit("That is not a valid email address.\n");
    try {
        DB::exec('sp_user_set_email', [$id, $newEmail]);
    } catch (\PDOException $e) {
        // 23000 is the duplicate-key family — the address is already in use
        exit($e->getCode() === '23000' ? "That email is already taken.\n" : $e->getMessage() . "\n");
    }
    echo "  email    -> $newEmail\n";
}

if ($newPass !== null) {
    if (strlen($newPass) < 10) exit("Passwords must be at least 10 characters.\n");
    DB::exec('sp_user_set_password', [$id, Auth::hash($newPass)]);
    echo "  password -> updated\n";
}

/* Every existing session for this account is destroyed.
 *
 * A credential change that leaves old sessions alive is close to pointless:
 * the usual reason to change a password is that someone else may have it, and
 * whoever they are is already signed in. This is what sp_session_kill_user was
 * built for. You will have to sign in again yourself — that is the point. */
DB::exec('sp_session_kill_user', [$id]);
echo "  sessions -> all signed out\n";

DB::exec('sp_audit_add', [
    $id, (string)$user['display_name'], 'user.credentials', 'user', $id,
    'changed from the command line', Ip::packed(),
]);

echo "\nDone. Sign in with the new details.\n";
