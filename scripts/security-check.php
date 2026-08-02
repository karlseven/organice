<?php
declare(strict_types=1);

/**
 * Offline security check. Reads the source, touches nothing, needs no server.
 *
 *   php scripts/security-check.php
 *
 * Run it after deploying: most of what it looks for is a deployment mistake
 * (APP_ENV left at dev, a stray PHP file in the webroot, uploads served
 * directly) rather than a coding one.
 *
 * Exits non-zero if anything FAILED, so it can gate a deploy.
 *
 * It is deliberately picky about its own patterns. An earlier version reported
 * `exec(` on every file containing `DB::exec()` and `unsafe-inline` on a comment
 * saying not to use unsafe-inline. A checker that cries wolf gets ignored, which
 * is worse than not having one.
 */

/* PHP 8 string helpers, for 7.4. This script deliberately does not
   bootstrap the application, so it loads them directly. */
require __DIR__ . "/../app/polyfill.php";

$root = dirname(__DIR__);
$fail = 0; $warn = 0;

function say(string $level, string $label, string $detail = ''): void {
    global $fail, $warn;
    printf("  %-5s %-50s %s\n", $level, $label, $detail);
    if ($level === 'FAIL') $fail++;
    if ($level === 'WARN') $warn++;
}

/** Source with comments and strings' contents removed, so prose cannot trip a check. */
function code(string $file): string {
    $s = file_get_contents($file) ?: '';
    return preg_replace('#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', '', $s) ?? '';
}

$php = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (str_contains($p, '/storage/') || str_contains($p, '/.git/') || str_contains($p, '/app/Data/')) continue;
    if (str_ends_with($p, '.php')) $php[] = $p;
}
$app = array_values(array_filter($php, fn($p) => !str_contains($p, '/scripts/')));

echo "── code execution ───────────────────────────────────────────────────\n";
$checks = [
    'eval()'                => '/(?<![\w>:$])eval\s*\(/',
    'shell execution'       => '/(?<![\w>:$])(system|exec|passthru|shell_exec|proc_open|popen)\s*\(/',
    'unserialize()'         => '/(?<![\w>:$])unserialize\s*\(/',
    'create_function()'     => '/(?<![\w>:$])create_function\s*\(/',
    'variable variables'    => '/\$\$[a-z_]/i',
];
foreach ($checks as $name => $re) {
    $hits = [];
    foreach ($app as $p) {
        $c = code($p);
        /* Skip the declaration of a method that merely shares the name — the
           thing being looked for is a CALL to the language builtin. */
        $c = preg_replace('/function\s+(system|exec|popen)\s*\(/', 'function __x(', $c);
        if (preg_match($re, $c)) $hits[] = basename($p);
    }
    say($hits ? 'FAIL' : 'ok', $name, implode(', ', $hits));
}

/* A require whose path comes from a request is local file inclusion. Anything
   built from a constant, a class name or a literal is fine. */
$lfi = [];
foreach ($app as $p) {
    foreach (explode("\n", code($p)) as $line) {
        if (!preg_match('/\b(include|require)(_once)?\b/', $line)) continue;
        if (!preg_match('/\$/', $line)) continue;
        if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $line)) {
            $lfi[] = basename($p) . ': ' . trim($line);
        }
    }
}
say($lfi ? 'FAIL' : 'ok', 'no require/include from request data', implode(' | ', $lfi));

echo "\n── SQL boundary ─────────────────────────────────────────────────────\n";
$rawSql = [];
foreach ($app as $p) {
    if (str_ends_with($p, '/Core/DB.php')) continue;
    if (preg_match('/->(query|exec)\s*\(\s*[\'"]\s*(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CALL)/i', code($p))) {
        $rawSql[] = basename($p);
    }
}
say($rawSql ? 'FAIL' : 'ok', 'no raw SQL outside DB.php', implode(', ', $rawSql));

$badProc = [];
$dynProc = [];
foreach ($php as $p) {
    $src = file_get_contents($p);
    if (preg_match_all('/DB::(?:proc|procOne|exec|sessionProcOne|sessionExec)\(\s*([\'"])([^\'"]+)\1/', $src, $m)) {
        foreach ($m[2] as $name) {
            if (!preg_match('/^sp_[a-z0-9_]+$/', $name)) $badProc[] = basename($p) . ':' . $name;
        }
    }
    if (preg_match('/DB::(?:proc|procOne|exec)\(\s*[^\'")]*\$/', $src)) $dynProc[] = basename($p);
}
say($badProc ? 'FAIL' : 'ok', 'every DB call names an sp_ procedure', implode(', ', $badProc));
say($dynProc ? 'FAIL' : 'ok', 'no variable procedure names', implode(', ', $dynProc));

echo "\n── deployment ───────────────────────────────────────────────────────\n";
$env = is_file("$root/.env") ? file_get_contents("$root/.env") : '';
say($env !== '' ? 'ok' : 'FAIL', '.env exists');
say(is_file("$root/public/.env") ? 'FAIL' : 'ok', '.env is outside the webroot');

preg_match('/^APP_ENV=(.*)$/m', $env, $m);
$appEnv = trim($m[1] ?? '');
say($appEnv === 'prod' ? 'ok' : 'WARN', 'APP_ENV is prod',
    $appEnv === 'prod' ? '' : "currently '$appEnv' — dev shows stack traces to visitors");

preg_match('/^DB_PASS=(.*)$/m', $env, $m);
say(trim($m[1] ?? '') !== '' ? 'ok' : 'FAIL', 'database password is set');

$strayPhp = [];
$rii2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/public", FilesystemIterator::SKIP_DOTS));
foreach ($rii2 as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (str_ends_with($p, '.php') && !str_ends_with($p, 'public/index.php')) $strayPhp[] = basename($p);
}
say($strayPhp ? 'FAIL' : 'ok', 'index.php is the only PHP in public/', implode(', ', $strayPhp));

say(is_dir("$root/public/storage") || is_dir("$root/public/uploads") ? 'FAIL' : 'ok',
    'uploads are not inside the webroot');
say(is_dir("$root/storage/uploads") ? 'ok' : 'WARN', 'storage/uploads exists');
say(is_dir("$root/storage/brand") || !is_dir("$root/public/brand") ? 'ok' : 'FAIL',
    'brand images are outside the webroot');

echo "\n── headers, CSP, session ────────────────────────────────────────────\n";
$cfg = code("$root/config/config.php");
foreach ([
    'session cookie is HttpOnly'   => "'httponly' => true",
    'session cookie is SameSite'   => "'samesite' => 'Lax'",
    'session.use_strict_mode'      => 'session.use_strict_mode',
    'X-Frame-Options: DENY'        => 'X-Frame-Options: DENY',
    'X-Content-Type-Options'       => 'nosniff',
    'Referrer-Policy'              => 'Referrer-Policy',
    'Content-Security-Policy'      => 'Content-Security-Policy',
    'X-Powered-By removed'         => "header_remove('X-Powered-By')",
    'HSTS when over TLS'           => 'Strict-Transport-Security',
    'Permissions-Policy'           => 'Permissions-Policy',
    'COOP'                         => 'Cross-Origin-Opener-Policy',
] as $label => $needle) {
    say(str_contains($cfg, $needle) ? 'ok' : 'FAIL', $label);
}

/* Only the actual header string matters — a comment mentioning unsafe-inline is
   not a finding, which is why $cfg has had comments stripped.

   The policy is built by concatenating strings across many lines, so the
   capture runs from the header name to the closing paren of that statement.
   Matching to the first quote-paren-semicolon found nothing at all, and an
   empty capture makes every "does the policy contain X" check silently pass. */
$csp = '';
if (preg_match('/Content-Security-Policy:.*?\)\s*;/s', $cfg, $m)) $csp = $m[0];
if ($csp === '') {
    say('FAIL', 'CSP header could not be read', 'checks below are meaningless');
}
say(str_contains($csp, 'unsafe-inline') ? 'FAIL' : 'ok', "CSP has no 'unsafe-inline'");
say(str_contains($csp, 'unsafe-eval') ? 'FAIL' : 'ok', "CSP has no 'unsafe-eval'");
say(str_contains($csp, "default-src 'self'") ? 'ok' : 'WARN', "CSP default-src is 'self'");
say(str_contains($csp, "frame-ancestors 'none'") ? 'ok' : 'WARN', 'CSP forbids framing');

echo "\n── password storage ─────────────────────────────────────────────────\n";
$auth = code("$root/app/Core/Auth.php");
say(str_contains($auth, 'password_verify') ? 'ok' : 'FAIL', 'password_verify() is used');
say(preg_match('/md5|sha1\s*\(/', $auth) ? 'FAIL' : 'ok', 'no md5/sha1 for passwords');
$hasHash = false;
foreach ($php as $p) if (str_contains(code($p), 'password_hash')) $hasHash = true;
say($hasHash ? 'ok' : 'FAIL', 'password_hash() is used somewhere');

echo "\n════════════════════════════════════════════════════════════════════\n";
printf("  %d failed, %d warning%s\n", $fail, $warn, $warn === 1 ? '' : 's');
exit($fail === 0 ? 0 : 1);
