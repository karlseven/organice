<?php
declare(strict_types=1);

namespace Controllers;

use Core\Auth;
use Core\DB;
use Core\HttpError;
use Core\I18n;
use Core\Markdown;
use Core\Perm;
use Core\Site;
use Core\Tree;
use Core\View;

final class PageController
{
    /**
     * GET /{path...} — single-space mode only.
     *
     * The site's own space has no /s/<slug> segment there, so this is the
     * catch-all that resolves a bare path against it. Registered last in the
     * route table and only reached when nothing else matched.
     */
    public function showSingle(string $path): void
    {
        if (!Site::isSingle()) throw new HttpError(404, 'That page does not exist.');
        /* $longForm: false. This request was NOT routed as /s/<space>/<path> —
           it only looks like it when the path itself happens to begin with
           "s/", which is what any /s/… URL that is not a real space falls
           through to. See the redirect below. */
        $this->show(Site::singleSlug(), $path, false);
    }

    /**
     * GET /s/{space}/{path...}
     *
     * @param bool $longForm true when the request really came in on the
     *        /s/<space>/<path> route, rather than through the single-mode
     *        catch-all.
     */
    public function show(string $spaceSlug, string $path, bool $longForm = true): void
    {
        $space = DB::procOne('sp_space_by_slug', [$spaceSlug, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');
        Perm::requireRead($space);

        /* In single mode the long /s/<slug>/<path> form still resolves, but it
           is no longer the canonical URL — so redirect to the short one. Every
           link anyone shared before the switch keeps working, which is the
           whole reason the old route is still registered. */
        /* Keyed on HOW THE REQUEST WAS ROUTED, not on what the URL looks like.
           Sniffing REQUEST_URI for "/s/" was wrong: in single mode a URL such as
           /s/not-a-real-space does not match the space route, falls through to
           the catch-all, and arrives here as the page path "s/not-a-real-space".
           The old check saw "/s/" in the URI, redirected to the short form of
           that path — which is the same URL — and the browser looped until it
           gave up. Any /s/ URL that was not a valid space was a redirect trap
           instead of a 404. */
        if ($longForm && Site::isSingle() && $spaceSlug === Site::singleSlug()) {
            header('Location: ' . Site::pageUrl($spaceSlug, trim($path, '/')), true, 301);
            exit;
        }

        $spaceId = (int)$space['id'];
        $drafts  = Perm::seesDrafts($space) ? 1 : 0;
        $path    = trim($path, '/');

        $page = DB::procOne('sp_page_by_path', [
            $spaceId, $path, I18n::current(), I18n::defaultLang(), $drafts,
        ]);

        if (!$page) {
            /* Before giving up, check whether this path used to be a page. Every
               rename and move leaves a redirect behind, which is what stops a
               reorganised book from breaking every link anyone ever shared. */
            $r = DB::procOne('sp_redirect_find', [$spaceId, $path]);
            if ($r) {
                header('Location: ' . Site::pageUrl((string)$space['slug'], (string)$r['path']), true, 301);
                exit;
            }
            throw new HttpError(404, 'That page does not exist.');
        }

        if ($page['status'] !== 'published' && !$drafts) {
            throw new HttpError(404, 'That page does not exist.');
        }

        $rows    = DB::proc('sp_page_tree', [$spaceId, $drafts, I18n::current()]);
        $ordered = Tree::ordered($rows);

        // prev/next in reading order, so a book can be read straight through
        $prev = $next = null;
        foreach ($ordered as $k => $row) {
            if ((int)$row['id'] !== (int)$page['id']) continue;
            $prev = $ordered[$k - 1] ?? null;
            $next = $ordered[$k + 1] ?? null;
            break;
        }

        $html  = (string)($page['content_html'] ?? '');
        $title = (string)($page['locale_title'] ?? $page['title']);

        /* Which languages a reader can switch to for THIS page. Languages
           without a translation are still listed — switching to one shows the
           default-language text with a notice, which is more useful than
           hiding the option and leaving them to wonder. */
        $locales = [];
        foreach (DB::proc('sp_page_locales', [(int)$page['id']]) as $l) {
            $locales[$l['lang']] = $l;
        }

        View::render('page/show', [
            'title'     => $title . ' · ' . $space['title'],
            'space'     => $space,
            'page'      => $page,
            'pageTitle' => $title,
            'tree'      => Tree::build($rows),
            'crumbs'    => Tree::crumbs($rows, (int)$page['id']),
            'toc'       => Markdown::tocFromHtml($html),
            'html'      => $html,
            'prev'      => $prev,
            'next'      => $next,
            'canEdit'   => Perm::canWrite($space),
            'locales'   => $locales,
            // the three states the reader is told about, from sp_page_by_path
            'isFallback' => (bool)($page['is_fallback'] ?? false),
            'isStale'    => (bool)($page['is_stale'] ?? false),
            'isMachine'  => ($page['source'] ?? '') === 'machine',
            'selfPath'   => Site::pagePathForSwitch((string)$space['slug'], (string)$page['path']),

            // ---- SEO --------------------------------------------------------
            'description' => Markdown::excerpt((string)($page['content_md'] ?? ''), 160),
            /* Drafts are obvious. A FALLBACK page is the subtle one: indexing
               /th/... while it is serving English would send Thai searchers to
               English text and, worse, teach the engine that the Thai URL is
               English. It is indexable again the moment a translation exists. */
            'noindex'     => $page['status'] !== 'published'
                          || (bool)($page['is_fallback'] ?? false),
        ]);
    }
}
