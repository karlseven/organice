<?php
declare(strict_types=1);

namespace Controllers;

use Core\DB;
use Core\I18n;
use Core\Site;
use Core\Settings;

/**
 * sitemap.xml and robots.txt.
 *
 * Both are generated rather than static files, because what belongs in them
 * depends on which spaces are public and which pages are published — state that
 * changes without anyone remembering to regenerate a file.
 */
final class SeoController
{
    /** GET /sitemap.xml */
    public function sitemap(): void
    {
        $rows = DB::proc('sp_sitemap');

        /* Group by page so each URL can carry xhtml:link alternates for its
           other languages. Without those a search engine treats the
           translations as competing duplicates instead of one document in
           several languages, and picks one more or less at random. */
        $byPage = [];
        foreach ($rows as $r) {
            $key = $r['space_slug'] . '/' . $r['path'];
            $byPage[$key]['path'] = $key;
            $byPage[$key]['langs'][$r['lang']] = $r['changed_at'];
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $base = rtrim($this->baseUrl(), '/');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
           . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($byPage as $page) {
            foreach ($page['langs'] as $lang => $changed) {
                // path here is "space/page-path"; split it back apart for Site::pageUrl
                [$sp, $pp] = array_pad(explode('/', $page['path'], 2), 2, '');
                $loc = $base . Site::pageUrl($sp, $pp, $lang);

                echo "  <url>\n";
                echo '    <loc>' . e($loc) . "</loc>\n";
                echo '    <lastmod>' . e(date('c', strtotime((string)$changed))) . "</lastmod>\n";

                foreach (array_keys($page['langs']) as $alt) {
                    echo '    <xhtml:link rel="alternate" hreflang="' . e($alt) . '" href="'
                       . e($base . Site::pageUrl($sp, $pp, $alt)) . "\"/>\n";
                }
                if (isset($page['langs'][I18n::defaultLang()])) {
                    echo '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                       . e($base . Site::pageUrl($sp, $pp, I18n::defaultLang())) . "\"/>\n";
                }

                echo "  </url>\n";
            }
        }

        echo "</urlset>\n";
        exit;
    }

    /** GET /robots.txt */
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $base = rtrim($this->baseUrl(), '/');

        echo "User-agent: *\n";

        /* The editor, the admin area and the API are not content. Disallow is a
           request, not a control — the real protection is that these routes
           require authentication — but keeping them out of an index stops
           sign-in pages turning up in search results for the site's own name. */
        echo 'Disallow: ' . url('/admin') . "\n";
        echo 'Disallow: ' . url('/edit/') . "\n";
        echo 'Disallow: ' . url('/api/') . "\n";
        echo 'Disallow: ' . url('/login') . "\n";
        echo 'Disallow: ' . url('/search') . "\n";
        echo "\n";
        echo 'Sitemap: ' . $base . url('/sitemap.xml') . "\n";
        exit;
    }

    /**
     * The public origin. Taken from APP_URL when set, because a site behind a
     * proxy sees a Host header that is not what readers typed, and a sitemap
     * full of internal hostnames is worse than none.
     */
    private function baseUrl(): string
    {
        $configured = (string)($GLOBALS['__env']['APP_URL'] ?? '');
        if ($configured !== '') return rtrim($configured, '/');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
