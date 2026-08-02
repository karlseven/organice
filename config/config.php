<?php
declare(strict_types=1);

// Bootstrap: paths, env, autoloader, session, security headers.
// See docs/ARCHITECTURE.md.

/*
 * Minimum PHP, checked before anything else runs.
 *
 * Without this the first thing 7.3 or lower hits is an undefined-function
 * fatal deep inside a file that looks fine, and the real cause — the PHP
 * version — is nowhere in the message. Cheap to check, and it turns a confusing
 * 500 into a sentence that says what to do.
 */
if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('organice needs PHP 7.4 or newer. This server is running ' . PHP_VERSION . '.');
}

/*
 * Required extensions, named plainly.
 *
 * Without this, a missing pdo_mysql surfaces as "Undefined class constant
 * MYSQL_ATTR_INIT_COMMAND" from deep inside Core\DB — a message that says
 * nothing about the actual cause, on a line that is perfectly correct. The same
 * goes for mbstring and fileinfo, which fail later and even less obviously.
 */
$missing = array_values(array_filter(
    ['pdo_mysql', 'mbstring', 'fileinfo', 'json'],
    static function (string $ext): bool { return !extension_loaded($ext); }
));
if ($missing) {
    http_response_code(500);
    exit(
        'organice needs these PHP extensions, which are not installed: '
        . implode(', ', $missing)
        . '. On Debian/Ubuntu: apt install '
        . implode(' ', array_map(
            static function (string $e): string {
                return 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-'
                     . ($e === 'pdo_mysql' ? 'mysql' : $e);
            },
            $missing
        ))
    );
}

/* PHP 8 string helpers for 7.4 — shared with the standalone scripts. */
require __DIR__ . "/../app/polyfill.php";

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('UPLOAD_PATH', BASE_PATH . '/storage/uploads');

// ---- .env loader (tiny, no deps) ----
$env = [];
$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}
/* Kept accessible for the few settings that are credentials rather than
   configuration — Core\Translator's API key. Those must NOT live in the
   settings table, which is editable from a web form and readable by every
   admin. */
$GLOBALS['__env'] = $env;

define('DB_HOST',   $env['DB_HOST']   ?? '127.0.0.1');
define('DB_PORT',   $env['DB_PORT']   ?? '3306');
define('DB_NAME',   $env['DB_NAME']   ?? 'organice');
define('DB_USER',   $env['DB_USER']   ?? 'organice_app');
define('DB_PASS',   $env['DB_PASS']   ?? '');
define('APP_ENV',   $env['APP_ENV']   ?? 'prod');
define('SITE_NAME', $env['SITE_NAME'] ?? 'Organice');

/*
 * Base URL path the app is mounted at: '' at a domain root, '/organice/public'
 * under an Apache Alias.
 *
 * Three sources, in order of trustworthiness:
 *
 *   1. APP_BASE in .env — explicit, and the only reliable answer behind a
 *      proxy or an unusual mount.
 *   2. Empty under the built-in server. Auto-detection CANNOT be used there:
 *      for a URL that looks like a file (/file/<sha>/x.png) the built-in server
 *      sets SCRIPT_NAME to the requested path rather than to the router script,
 *      so dirname() yields '/file/<sha>' and every such request has its real
 *      path stripped away and 404s. That cost an afternoon.
 *   3. dirname(SCRIPT_NAME) — correct under Apache and nginx, where
 *      SCRIPT_NAME really is the front controller.
 */
/* An EMPTY APP_BASE= line means "work it out", not "the domain root" — the
   example .env ships that key blank, and reading it as an answer would silently
   break every subdirectory install that copied the file unchanged. */
if (trim($env['APP_BASE'] ?? '') !== '') {
    define('APP_BASE', rtrim(trim($env['APP_BASE']), '/'));
} elseif (PHP_SAPI === 'cli-server') {
    define('APP_BASE', '');
} else {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    define('APP_BASE', $scriptDir === '/' ? '' : rtrim($scriptDir, '/'));
}

ini_set('display_errors', APP_ENV === 'dev' ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

require APP_PATH . '/helpers.php';

// ---- autoloader for app/Core and app/Controllers ----
spl_autoload_register(function (string $class): void {
    $file = APP_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require $file;
});

// ---- hardened session ----
session_name('organice_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
/* Refuse a session id the browser made up rather than one we issued — without
   this, an attacker can plant a known id in the victim's browser and be holding
   a valid session the moment they sign in. */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '5');

/* Sessions live in MySQL, not on this machine's disk — see Core\Session. The
   handler must be installed BEFORE session_start(), and it needs the
   autoloader, which is why this sits here rather than up with the other ini
   settings.

   Wrapped because a site whose database is unreachable must still be able to
   render its error page; falling back to files loses multi-server correctness
   but not the ability to say what went wrong. */
try {
    Core\Session::install();
} catch (\Throwable $e) {
    error_log('session handler unavailable, falling back to files: ' . $e->getMessage());
}

session_start();

/*
 * Per-request nonce for the one <style> block carrying page-specific values
 * (sidebar width, a space's accent colour). `style-src 'self'` blocks style=""
 * ATTRIBUTES, so dynamic styling must go through this nonced block — see
 * css_add() in app/helpers.php. Do not add 'unsafe-inline' to work around it.
 */
define('CSP_NONCE', base64_encode(random_bytes(16)));

/*
 * Docs pages are public-readable and mostly identical for every reader, but the
 * layout carries a per-request CSP nonce and the header shows who is signed in,
 * so a shared cache would serve one reader's session state to another.
 * Static assets are served straight by Apache and are unaffected.
 */
/* PHP advertises its exact version in X-Powered-By by default, which tells an
   attacker precisely which CVEs to try. `expose_php` is a php.ini setting and
   cannot be changed at runtime, but the header can simply be removed. */
header_remove('X-Powered-By');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

/* No page here uses a camera, microphone, location or payment API. Declaring
   that explicitly means a script that somehow got onto the page cannot use
   them either. */
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()');

/* Severs the reference a window opened from here (or one that opened this one)
   would otherwise keep, which is what makes tab-napping and cross-origin
   window probing possible. */
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');

/* HSTS only when the request actually arrived over TLS. Sending it over plain
   HTTP is meaningless, and sending it from a dev box on localhost would pin
   the browser to HTTPS for a host that does not serve it. */
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/*
 * img-src allows any https host because an author may reference a diagram or
 * badge hosted elsewhere; every such <img> is emitted with
 * referrerpolicy="no-referrer" and loading="lazy" (see Core\Markdown) so a
 * third party learns an IP but never which page it came from.
 *
 * Uploaded assets are served from 'self' through AssetController, never from
 * the webroot — see docs/SECURITY notes in ARCHITECTURE.md.
 */
/*
 * frame-src names the two video hosts by exact origin and nothing else. An
 * embed is a third-party document running in a frame of this site, so this list
 * is an allow-list on purpose — see Core\Embed.
 *
 * Nothing is framed until a reader presses play: the embed renders as a
 * thumbnail and the iframe is inserted on click. A reader who never presses
 * play never contacts these hosts.
 */
header("Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data: https:; "
    /* The nonce must be listed for SCRIPTS as well as styles. Both the layout
       and the editor pass server-side values (APP_BASE, the CSRF token, the
       translated UI strings) to the front end through a nonced inline <script>.
       With `script-src 'self'` alone those blocks are refused, silently — no
       console error the page can catch, no failed request, just an editor whose
       buttons do nothing because window.ED was never assigned. */
    . "script-src 'self' 'nonce-" . CSP_NONCE . "'; "
    . "style-src 'self' 'nonce-" . CSP_NONCE . "'; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
    . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
