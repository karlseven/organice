<?php
declare(strict_types=1);

/**
 * Find internal links that no longer resolve.
 *
 * Only CURRENT revisions are checked. Old revisions are historical records —
 * they are supposed to describe the site as it was, and reporting every link
 * that has moved since 2019 would bury the ones that matter today.
 *
 * A link that hits the redirects table counts as working, because it is: the
 * reader gets a 301 to the right place. It is reported separately anyway, since
 * a link that could point straight at its target is worth tidying.
 *
 * Usage:
 *   php scripts/check-links.php              broken links only
 *   php scripts/check-links.php --redirects  also list ones going via a redirect
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line.\n");
}

require dirname(__DIR__) . '/config/config.php';

use Core\DB;
use Core\I18n;

$showRedirects = in_array('--redirects', array_slice($argv, 1), true);

// ---------------------------------------------------------------------------
// 1. every path that exists, per space
// ---------------------------------------------------------------------------
$spaces = DB::proc('sp_spaces_visible', [0, 1]);
$paths  = [];   // "space-slug/path" => true
$bySlug = [];

foreach ($spaces as $s) {
    $bySlug[$s['slug']] = (int)$s['id'];
    foreach (DB::proc('sp_page_tree', [(int)$s['id'], 1, I18n::defaultLang()]) as $p) {
        $paths[$s['slug'] . '/' . $p['path']] = true;
    }
}

echo count($paths) . " page(s) across " . count($spaces) . " space(s)\n\n";

// ---------------------------------------------------------------------------
// 2. scan current revisions
// ---------------------------------------------------------------------------
$broken = [];
$viaRedirect = [];
$checked = 0;

foreach ($spaces as $s) {
    foreach (DB::proc('sp_page_tree', [(int)$s['id'], 1, I18n::defaultLang()]) as $p) {
        foreach (I18n::enabled() as $lang) {
            $page = DB::procOne('sp_page_by_id', [(int)$p['id'], $lang]);
            if (!$page || ($page['content_md'] ?? null) === null) continue;

            $md = (string)$page['content_md'];
            $where = $s['slug'] . '/' . $p['path'] . ' [' . $lang . ']';

            foreach (linksIn($md) as [$target, $line]) {
                $checked++;

                // strip any language prefix before resolving — it is chrome,
                // not part of the page's identity
                $rel = ltrim($target, '/');
                foreach (I18n::enabled() as $code) {
                    if ($code !== I18n::defaultLang() && str_starts_with($rel, $code . '/')) {
                        $rel = substr($rel, strlen($code) + 1);
                    }
                }
                if (!str_starts_with($rel, 's/')) continue;   // not a page link

                $rel = substr($rel, 2);
                [$slug, $path] = array_pad(explode('/', $rel, 2), 2, '');
                if ($path === '') continue;

                if (isset($paths[$slug . '/' . $path])) continue;   // fine

                // does a redirect cover it?
                $hit = isset($bySlug[$slug])
                    ? DB::procOne('sp_redirect_find', [$bySlug[$slug], $path])
                    : null;

                if ($hit) {
                    $viaRedirect[] = [$where, $line, $target, $slug . '/' . $hit['path']];
                } else {
                    $broken[] = [$where, $line, $target];
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. report
// ---------------------------------------------------------------------------
echo "Checked $checked internal link(s).\n\n";

if ($broken === []) {
    echo "No broken links.\n";
} else {
    echo "BROKEN (" . count($broken) . "):\n";
    foreach ($broken as [$where, $line, $target]) {
        echo "  $where line $line\n    -> $target\n";
    }
}

if ($showRedirects && $viaRedirect !== []) {
    echo "\nVia redirect (" . count($viaRedirect) . ") — these work, but could point straight at the target:\n";
    foreach ($viaRedirect as [$where, $line, $target, $now]) {
        echo "  $where line $line\n    $target  ->  /s/$now\n";
    }
} elseif (!$showRedirects && $viaRedirect !== []) {
    echo "\n" . count($viaRedirect) . " link(s) resolve via a redirect. Pass --redirects to list them.\n";
}

exit($broken === [] ? 0 : 1);

/**
 * Site-relative link targets and the line each is on. External links are not
 * checked — that needs network requests, is slow and flaky, and a 200 from
 * someone else's server today says nothing about tomorrow.
 *
 * @return array<int,array{0:string,1:int}>
 */
function linksIn(string $md): array
{
    $out = [];

    // fenced code holds examples, not links anyone can follow
    $md = preg_replace('/^\s*(`{3,}|~{3,})[\s\S]*?^\s*\1[^\n]*$/m', '', $md) ?? $md;

    foreach (explode("\n", $md) as $n => $line) {
        if (!preg_match_all('/(?<!!)\[[^\]]*\]\(([^)\s]+)/', $line, $m)) continue;
        foreach ($m[1] as $href) {
            if ($href === '' || $href[0] === '#') continue;
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) continue;   // absolute
            if ($href[0] !== '/') continue;                              // relative, skip
            $out[] = [strtok($href, '#'), $n + 1];
        }
    }
    return $out;
}
