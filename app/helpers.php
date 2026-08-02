<?php
declare(strict_types=1);

// Global view/response helpers — required from config/config.php.

/** Escape for HTML text and attribute context. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute app URL for a path, honouring APP_BASE. Language-neutral. */
function url(string $path = '/'): string
{
    return APP_BASE . '/' . ltrim($path, '/');
}

/**
 * A URL inside the CURRENT language.
 *
 * Use this for every in-app link that should keep the reader where they are.
 * Plain url() is for language-neutral endpoints — /login, /api/*, /file/* —
 * which have no translated form.
 */
function lurl(string $path = '/'): string
{
    return url(Core\I18n::prefix() . '/' . ltrim($path, '/'));
}

/**
 * A translated UI string. `:name` placeholders come from $vars.
 * Not escaped — views escape at the point of output, as with any other value.
 *
 * @param array<string,string|int> $vars
 */
function t(string $key, array $vars = []): string
{
    return Core\I18n::t($key, $vars);
}

/**
 * Versioned static asset URL. The ?v=<filemtime> means the URL changes whenever
 * the file does, which is what makes the one-year cache header in
 * public/.htaccess safe.
 */
function asset(string $path): string
{
    $rel  = 'assets/' . ltrim($path, '/');
    $file = BASE_PATH . '/public/' . $rel;
    $v    = is_file($file) ? filemtime($file) : 0;
    return url($rel) . '?v=' . $v;
}

/*
 * `void`, not `never`, and no `mixed` on $data — both are PHP 8 types and this
 * app supports 7.4 upward.
 *
 * These two are worse than a parse error, which is why they are called out:
 * PHP 7.4 does not RECOGNISE `never` or `mixed`, it reads them as class names.
 * The file parses, `php -l` is happy, and nothing looks wrong — until
 * json_out() is passed an array and 7.4 raises "Argument 1 must be an instance
 * of mixed, array given" from a line that has been there all along.
 */
function redirect(string $path): void
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Queue a CSS rule for this page. Views must use this instead of a style=""
 * attribute — the CSP forbids inline style attributes, so they are silently
 * dropped by the browser. The layout prints everything queued here inside a
 * nonced <style> block.
 */
function css_add(string $rule): void
{
    $GLOBALS['__css'][] = $rule;
}

function css_out(): string
{
    return implode("\n", $GLOBALS['__css'] ?? []);
}

/** One-shot message shown on the next rendered page. */
function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['__flash'] = ['msg' => $msg, 'kind' => $kind];
        return null;
    }
    $f = $_SESSION['__flash'] ?? null;
    unset($_SESSION['__flash']);
    return $f;
}

/**
 * Inline flat line icon (stroke = currentColor).
 *
 * The short names accepted here (trash, caretR, close, …) are aliases onto the
 * bundled Lucide set — see Core\Icon::ALIAS. They used to be two dozen paths
 * pasted into this file by hand; the app now has one icon vocabulary.
 */
function icon(string $name, int $size = 18): string
{
    return Core\Icon::chrome($name, $size);
}
/** Human "3 days ago" for revision lists. */
function ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return intdiv($d, 60) . 'm ago';
    if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}
