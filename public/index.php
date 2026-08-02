<?php
declare(strict_types=1);

// Front controller — see docs/ARCHITECTURE.md

// PHP built-in server: serve existing static files directly
if (PHP_SAPI === 'cli-server') {
    $path = urldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/config/config.php';

use Core\Auth;
use Core\Csrf;
use Core\View;
use Controllers\AdminController;
use Controllers\AssetController;
use Controllers\AuthController;
use Controllers\BrandController;
use Controllers\EditorController;
use Controllers\PageController;
use Controllers\SearchController;
use Controllers\SeoController;
use Controllers\SpaceController;

/*
 * [method, path-regex, handler, access]
 *   access: false (public) | 'auth' (signed in) | 'admin'
 *
 * "public" here means the ROUTE is reachable without signing in; whether a
 * particular space is readable is a separate check inside the controller
 * (Core\Perm), because a private space must 404 for a stranger even though the
 * route itself is open.
 */
$routes = [
    ['GET',  '#^/$#',                              [SpaceController::class, 'index'],      false],

    ['GET',  '#^/login$#',                         [AuthController::class, 'showLogin'],   false],
    ['POST', '#^/login$#',                         [AuthController::class, 'login'],       false],
    ['POST', '#^/logout$#',                        [AuthController::class, 'logout'],      'auth'],

    ['GET',  '#^/sitemap\.xml$#',                  [SeoController::class, 'sitemap'],      false],
    ['GET',  '#^/robots\.txt$#',                   [SeoController::class, 'robots'],       false],

    ['GET',  '#^/search$#',                        [SearchController::class, 'index'],     false],
    ['GET',  '#^/api/search$#',                    [SearchController::class, 'api'],       false],

    /* Uploaded files are served through the app, never straight off disk, so a
       private space's diagrams stay private. The sha in the URL makes the
       response immutable and safe to cache hard. */
    ['GET',  '#^/file/([a-f0-9]{64})/([^/]+)$#',   [AssetController::class, 'show'],       false],
    ['POST', '#^/api/upload$#',                    [AssetController::class, 'upload'],     'auth'],

    // ---- editing (page id, not path: a page keeps its id when it is renamed
    //      or moved, so an open editor tab never points at the wrong page) ----
    ['GET',  '#^/edit/(\d+)$#',                    [EditorController::class, 'edit'],      'auth'],
    ['POST', '#^/api/preview$#',                   [EditorController::class, 'preview'],   'auth'],
    ['POST', '#^/api/pages$#',                     [EditorController::class, 'create'],    'auth'],
    ['POST', '#^/api/pages/(\d+)$#',               [EditorController::class, 'save'],      'auth'],
    ['POST', '#^/api/pages/(\d+)/move$#',          [EditorController::class, 'move'],      'auth'],
    ['POST', '#^/api/pages/(\d+)/delete$#',        [EditorController::class, 'delete'],    'auth'],
    ['POST', '#^/api/pages/(\d+)/translate$#',     [EditorController::class, 'translate'], 'auth'],
    ['POST', '#^/api/pages/(\d+)/untranslate$#',   [EditorController::class, 'untranslate'], 'auth'],
    ['GET',  '#^/api/pages/(\d+)/revisions$#',     [EditorController::class, 'revisions'], 'auth'],
    ['GET',  '#^/api/pages/(\d+)/diff$#',          [EditorController::class, 'diff'],      'auth'],
    ['GET',  '#^/api/revisions/(\d+)$#',           [EditorController::class, 'revision'],  'auth'],
    ['POST', '#^/api/pages/(\d+)/revert/(\d+)$#',  [EditorController::class, 'revert'],    'auth'],

    // ---- admin ----
    ['GET',  '#^/admin$#',                         [AdminController::class, 'index'],      'admin'],
    ['POST', '#^/admin/spaces$#',                  [AdminController::class, 'createSpace'], 'admin'],
    ['POST', '#^/admin/spaces/(\d+)$#',            [AdminController::class, 'updateSpace'], 'admin'],
    ['POST', '#^/admin/spaces/(\d+)/delete$#',     [AdminController::class, 'deleteSpace'], 'admin'],
    ['GET',  '#^/admin/spaces/(\d+)/members$#',    [AdminController::class, 'members'],    'admin'],
    ['POST', '#^/admin/spaces/(\d+)/members$#',    [AdminController::class, 'setMember'],  'admin'],
    ['GET',  '#^/admin/settings$#',                [AdminController::class, 'settings'],     'admin'],
    ['POST', '#^/admin/settings$#',                [AdminController::class, 'saveSettings'], 'admin'],
    ['GET',  '#^/admin/audit$#',                   [AdminController::class, 'audit'],      'admin'],
    ['GET',  '#^/admin/users$#',                   [AdminController::class, 'users'],      'admin'],
    ['POST', '#^/admin/users$#',                   [AdminController::class, 'createUser'], 'admin'],
    ['POST', '#^/admin/users/(\d+)$#',             [AdminController::class, 'updateUser'], 'admin'],

    ['GET',  '#^/brand/([\w.-]+)$#',                [BrandController::class, 'show'],       false],

    /* Space routes come LAST: their path patterns are the broadest, and a page
       slugged "search" or "admin" must not shadow a real route. */
    ['GET',  '#^/s/([a-z0-9][a-z0-9-]*)$#',        [SpaceController::class, 'show'],       false],
    ['GET',  '#^/s/([a-z0-9][a-z0-9-]*)/(.+)$#',   [PageController::class, 'show'],        false],

    /* Absolutely last. In single-space mode a page lives at /<path> with no
       /s/<slug> segment, so this catches anything left over. It matches almost
       everything, which is exactly why nothing may be added below it — and why
       Core\Site::reservedSlugs() refuses to let a root page take a slug that
       one of the routes above already owns. */
    ['GET',  '#^/(.+)$#',                          [PageController::class, 'showSingle'],  false],
];

$method = $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'GET' : $_SERVER['REQUEST_METHOD'];
$path   = rtrim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (APP_BASE !== '' && str_starts_with($path, APP_BASE)) {
    $path = substr($path, strlen(APP_BASE));
}
$path = $path === '' ? '/' : $path;

/*
 * Strip a leading language segment before the route table sees the path, so
 * every route is written once and works in every language.
 *
 * The DEFAULT language has no prefix, which is what keeps URLs that existed
 * before this feature working unchanged. A site with one language never grows a
 * locale segment at all.
 *
 * An unknown first segment (/nope) is left alone deliberately — it must fall
 * through to a 404 rather than being silently read as a language.
 */
if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $lm) && Core\I18n::isEnabled($lm[1])
    && $lm[1] !== Core\I18n::defaultLang()) {
    Core\I18n::set($lm[1]);
    $path = $lm[2] ?? '/';
    $path = $path === '' ? '/' : $path;
} else {
    /* No prefix means the default language — for the content AND the chrome.
       The URL is the single authority: a page that shows English text under a
       Thai interface is confusing, and worse, it makes the language of a shared
       link depend on who opens it. */
    Core\I18n::set(Core\I18n::defaultLang());

    /* The reader's preference is honoured at the front door only. Redirecting
       every unprefixed URL would make every shared link bounce and would defeat
       caching; doing it just for '/' gets someone to their language once,
       after which every link they follow already carries the prefix. */
    if ($path === '/' && $method === 'GET') {
        $pref = Core\I18n::negotiate();
        if ($pref !== Core\I18n::defaultLang()) {
            header('Location: ' . url(Core\I18n::prefix($pref) . '/'), true, 302);
            exit;
        }
    }
}

/*
 * `?setlang=th` switches the READING language and remembers it — the switcher
 * is a plain link, so it works without JavaScript.
 *
 * Deliberately NOT spelled `lang`. The editor already uses `?lang=` to name the
 * language being EDITED, which is a genuinely different question: a translator
 * writes Thai while reading English. Sharing one parameter name made every
 * editor request bounce through a 302 instead of doing its job, and no amount
 * of path-exclusion makes two meanings for one name safe to keep.
 */
$want = $_GET['setlang'] ?? '';
if ($method === 'GET' && is_string($want) && $want !== '' && Core\I18n::isEnabled($want)) {
    Core\I18n::remember($want);
    header('Location: ' . url(Core\I18n::prefix($want) . $path), true, 302);
    exit;
}

try {
    foreach ($routes as [$m, $re, $handler, $access]) {
        if ($m !== $method || !preg_match($re, $path, $args)) continue;
        array_shift($args);

        /* Every state change is a POST and every POST is token-checked here, so
           an individual controller cannot forget to do it. */
        if ($method === 'POST') Csrf::check();

        /* Anyone not signed in goes to the sign-in page, whichever level the
           route needs. Answering 403 to an anonymous request was both unhelpful
           — an admin who had simply been signed out got a dead end instead of a
           login form — and a small signal, since it distinguishes "this route
           exists and needs admin" from "no such route". */
        if ($access !== false && !Auth::check()) {
            redirect('/login?next=' . rawurlencode($path));
        }
        // signed in but not an admin: this IS a refusal, and 403 is the truth
        if ($access === 'admin' && !Auth::isAdmin()) throw new Core\HttpError(403);

        [$class, $fn] = $handler;
        (new $class)->$fn(...$args);
        exit;
    }
    throw new Core\HttpError(404);
} catch (Core\HttpError $err) {
    fail($path, $err->status, $err->getMessage());
} catch (Throwable $err) {
    error_log((string)$err);
    fail($path, 500, APP_ENV === 'dev' ? $err->getMessage() : '');
}

/**
 * The editor talks to /api/* over fetch() and parses every response as JSON;
 * handing it an HTML error page turns a clear "slug already taken" into an
 * unexplained parse failure. Everything else gets the error page.
 */
/* `void` not `never` — see the note in app/helpers.php. */
function fail(string $path, int $status, string $message): void
{
    if (str_starts_with($path, '/api/')) {
        json_out(['error' => true, 'status' => $status, 'message' => $message], $status);
    }
    http_response_code($status);
    View::render('errors/error', ['status' => $status, 'message' => $message]);
    exit;
}
