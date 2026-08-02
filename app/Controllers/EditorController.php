<?php
declare(strict_types=1);

namespace Controllers;

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Diff;
use Core\HttpError;
use Core\I18n;
use Core\Markdown;
use Core\Perm;
use Core\Request;
use Core\Site;
use Core\Slug;
use Core\Throttle;
use Core\Translator;
use Core\Tree;
use Core\View;

/**
 * Editing. Every write here goes through a stored procedure and every response
 * is JSON except edit(), which serves the editor shell.
 *
 * The editor stores Markdown and nothing else. That is why there is no "format"
 * column anywhere and no branch in this controller for one. A rich text mode was
 * built over this and removed again; it never needed a change here, because any
 * such mode is a front-end concern that serialises back to Markdown before it
 * posts to save(). See docs/EDITOR.md.
 *
 * EVERY method here works in one language, named explicitly by the request
 * rather than inherited from the URL prefix. An editor writing the Thai version
 * of a page is not browsing in Thai — they are usually reading the English
 * source beside it — so the two must not be the same setting.
 */
final class EditorController
{
    /** GET /edit/{id} — the editor shell (no site chrome, it owns the viewport). */
    public function edit(string $id): void
    {
        $lang = $this->lang();
        [$page, $space] = $this->load((int)$id, $lang);

        $rows    = DB::proc('sp_page_tree', [(int)$space['id'], 1, $lang]);
        $locales = DB::proc('sp_page_locales', [(int)$page['id']]);

        /* The default-language text is handed to the editor so a translator can
           see what they are translating without a second window. */
        $source = null;
        if ($lang !== I18n::defaultLang()) {
            $source = DB::procOne('sp_page_by_id', [(int)$page['id'], I18n::defaultLang()]);
        }

        View::bare('page/edit', [
            'title'    => 'Editing ' . ($page['locale_title'] ?? $page['title']),
            'page'     => $page,
            'space'    => $space,
            'flat'     => Tree::ordered($rows),
            'token'    => Csrf::token(),
            'lang'     => $lang,
            'locales'  => $locales,
            'source'   => $source,
            'canTranslate' => Translator::available(),
        ]);
    }

    /** POST /api/preview — live preview pane. */
    public function preview(): void
    {
        // signed-in only, but it runs the whole parser on arbitrary input
        Throttle::guard('preview', 240, 60);

        $md = (string)(Request::json()['content'] ?? '');
        json_out(['html' => Markdown::render($md)['html']]);
    }

    /** POST /api/pages — create a page in a space. */
    public function create(): void
    {
        $in    = Request::json();
        $space = DB::procOne('sp_space_by_id', [(int)($in['space_id'] ?? 0), Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');
        Perm::requireRead($space);
        Perm::requireWrite($space);

        $title  = trim((string)($in['title'] ?? '')) ?: 'Untitled';
        $parent = (int)($in['parent_id'] ?? 0);

        /* A page is always born in the DEFAULT language, whatever the creator
           happens to be browsing in. The default language is every other one's
           fallback, so a page that existed only in Thai would show a blank
           title to every reader in every other language. */
        $lang = I18n::defaultLang();

        /* Uniqueness is settled here, before the insert, because the path a
           page ends up at is (parent path + slug) and the unique key is on the
           path — a colliding slug would surface as a raw duplicate-key error
           from deep inside sp_page_paths_rebuild. */
        $siblings = array_filter(
            DB::proc('sp_page_tree', [(int)$space['id'], 1, $lang]),
            static fn(array $r): bool => (int)($r['parent_id'] ?? 0) === $parent
        );
        $slug = Slug::unique(Slug::make($title), $siblings);

        /* A ROOT page in single-space mode lives at /<slug>, so it must not take
           a segment the application already owns — a page called "Search" would
           otherwise shadow /search and the site would lose its search box. */
        if ($parent === 0 && Site::isReserved($slug)) {
            $slug = Slug::unique($slug . '-page', $siblings);
        }

        $new = DB::procOne('sp_page_create', [
            (int)$space['id'], $parent, $slug, $title, Auth::id(), $lang,
        ]);
        if (!$new) throw new HttpError(500, 'Could not create the page.');

        // an empty page has no revision yet; give it one so the editor and the
        // search index both have something consistent to read
        DB::proc('sp_revision_create', [
            (int)$new['id'], $lang, Auth::id(), $title, '', '', '', 'Created', 'human', 0, $lang,
        ]);

        json_out(['id' => (int)$new['id'], 'edit_url' => url('/edit/' . $new['id'])], 201);
    }

    /** POST /api/pages/{id} — save a revision in one language. */
    public function save(string $id): void
    {
        $lang = $this->lang();
        [$page, $space] = $this->load((int)$id, $lang);

        $in      = Request::json();
        $md      = (string)($in['content'] ?? '');
        $title   = trim((string)($in['title'] ?? '')) ?: (string)($page['locale_title'] ?? $page['title']);
        $status  = ($in['status'] ?? $page['status']) === 'published' ? 'published' : 'draft';
        $summary = mb_substr(trim((string)($in['summary'] ?? '')), 0, 255);

        $r = Markdown::render($md);

        /* Rename first: sp_page_rename recomputes paths and records the
           redirect, and the revision below must be attached to the page in its
           final shape so the search row and the path agree. */
        $wanted = trim((string)($in['slug'] ?? $page['slug']));
        $slug   = Slug::make($wanted !== '' ? $wanted : $title, $page['slug']);
        $path   = $page['path'];

        $renamed = $slug !== $page['slug']
                || $status !== $page['status']
                || $title !== (string)($page['locale_title'] ?? $page['title']);

        if ($renamed) {
            $siblings = array_filter(
                DB::proc('sp_page_tree', [(int)$space['id'], 1, $lang]),
                static fn(array $x): bool =>
                    (int)($x['parent_id'] ?? 0) === (int)($page['parent_id'] ?? 0)
            );
            $slug = Slug::unique($slug, $siblings, (int)$page['id']);
            $res  = DB::procOne('sp_page_rename', [
                (int)$page['id'], $slug, $title, $status, $lang, I18n::defaultLang(),
            ]);
            $path = $res['path'] ?? $path;
        }

        /* A human saving a translation clears the machine flag and passes 0 for
           the source revision — they have not necessarily read the source, and
           claiming they translated from a revision they never saw would make
           the staleness banner lie in the reader's favour. */
        /* The icon belongs to the page, not to a translation, so it is written
           whichever language is being edited. Sanitised in Core\Slug because
           what arrives is author input like any other. */
        if (array_key_exists('icon', $in)) {
            $icon = Slug::icon((string)$in['icon']);
            if ($icon !== (string)($page['icon'] ?? '')) {
                DB::exec('sp_page_set_icon', [(int)$page['id'], $icon]);
            }
        }

        DB::proc('sp_revision_create', [
            (int)$page['id'], $lang, Auth::id(), $title, $md, $r['html'], $r['text'],
            $summary, 'human', 0, I18n::defaultLang(),
        ]);

        // per-language publish state lives on the locale row
        DB::exec('sp_locale_status', [(int)$page['id'], $lang, $status]);

        json_out([
            'ok'   => true,
            'slug' => $slug,
            'path' => $path,
            'lang' => $lang,
            'url'  => Site::pageUrl((string)$space['slug'], (string)$path, $lang),
            'saved_at' => date('c'),
        ]);
    }

    /**
     * POST /api/pages/{id}/translate — machine-translate into one language.
     *
     * The result is saved as a DRAFT marked `machine`, never published
     * directly: automatic output is a starting point for a person, not
     * something to put in front of readers unreviewed.
     */
    public function translate(string $id): void
    {
        $in   = Request::json();
        $to   = (string)($in['lang'] ?? '');
        $from = I18n::defaultLang();

        if (!I18n::isEnabled($to) || $to === $from) {
            throw new HttpError(422, 'Pick a language to translate into.');
        }
        // each call costs real money at the translation provider
        Throttle::guard('translate', 20, 60);

        if (!Translator::available()) {
            throw new HttpError(503, 'Machine translation is not configured. See .env (MT_KEY).');
        }

        [$page, $space] = $this->load((int)$id, $from);

        /* Machine translation sends the page's text to a third party. For a
           public space that is no more exposure than publishing it. For an
           internal or private one it is a disclosure the space's whole point
           was to prevent, so it takes a deliberate opt-in rather than being
           available by default. */
        if ($space['visibility'] !== 'public' && !$this->mtAllowsPrivate()) {
            throw new HttpError(
                403,
                'This space is not public, and machine translation would send its '
                . 'contents to an external service. Enable "mt_allow_private" in '
                . 'settings if that is acceptable.'
            );
        }

        $source = DB::procOne('sp_page_by_id', [(int)$page['id'], $from]);
        $md     = (string)($source['content_md'] ?? '');
        if (trim($md) === '') throw new HttpError(422, 'There is nothing to translate.');

        $translated = Translator::markdown($md, $from, $to);
        $title      = Translator::markdown((string)($source['locale_title'] ?? $page['title']), $from, $to);
        $r          = Markdown::render($translated);

        DB::proc('sp_revision_create', [
            (int)$page['id'], $to, Auth::id(), $title, $translated, $r['html'], $r['text'],
            'Machine translated from ' . $from, 'machine',
            // records WHICH source revision this came from, so the translation
            // can report itself stale once the source moves on
            (int)($source['current_revision_id'] ?? 0),
            $from,
        ]);
        DB::exec('sp_locale_status', [(int)$page['id'], $to, 'draft']);

        Audit::log('page.translate', 'page', (int)$page['id'], $from . '->' . $to);

        json_out(['ok' => true, 'lang' => $to, 'title' => $title, 'content' => $translated]);
    }

    /** POST /api/pages/{id}/move — reparent and/or reorder. */
    public function move(string $id): void
    {
        $lang = I18n::defaultLang();
        [$page, $space] = $this->load((int)$id, $lang);

        $in     = Request::json();
        $parent = (int)($in['parent_id'] ?? 0);

        /* Placement is an intent, not an index — see sp_page_move. Anything
           unrecognised becomes 'last', which is the harmless default: the page
           still moves to the requested parent and merely lands at the bottom. */
        $mode  = (string)($in['mode'] ?? 'last');
        if (!in_array($mode, ['first', 'last', 'after'], true)) $mode = 'last';
        $after = (int)($in['after_id'] ?? 0);

        if ($mode === 'after' && $after <= 0) $mode = 'last';

        /* A page cannot be moved under its own descendant: the tree would
           become a detached ring, sp_page_paths_rebuild would never reach it,
           and it would vanish from the sidebar with no way back. */
        if ($parent > 0) {
            $rows = DB::proc('sp_page_tree', [(int)$space['id'], 1, $lang]);
            foreach (Tree::crumbs($rows, $parent) as $ancestor) {
                if ((int)$ancestor['id'] === (int)$page['id']) {
                    throw new HttpError(422, 'A page cannot be moved inside itself.');
                }
            }
        }

        $res = DB::procOne('sp_page_move', [(int)$page['id'], $parent, $mode, $after]);
        json_out(['ok' => true, 'path' => $res['path'] ?? $page['path']]);
    }

    /** POST /api/pages/{id}/delete — takes the subtree, and every language. */
    public function delete(string $id): void
    {
        [$page, $space] = $this->load((int)$id, I18n::defaultLang());
        Audit::log('page.delete', 'page', (int)$page['id'],
            $space['slug'] . '/' . $page['path']);
        DB::exec('sp_page_delete', [(int)$page['id']]);
        json_out(['ok' => true, 'redirect' => Site::spaceUrl((string)$space['slug'])]);
    }

    /** POST /api/pages/{id}/untranslate — drop ONE language of a page. */
    public function untranslate(string $id): void
    {
        $lang = $this->lang();
        if ($lang === I18n::defaultLang()) {
            throw new HttpError(422, 'The default language cannot be removed — delete the page instead.');
        }

        [$page] = $this->load((int)$id, $lang);
        DB::exec('sp_locale_delete', [(int)$page['id'], $lang]);
        Audit::log('page.untranslate', 'page', (int)$page['id'], $lang);

        json_out(['ok' => true]);
    }

    /** GET /api/pages/{id}/revisions */
    public function revisions(string $id): void
    {
        $lang = $this->lang();
        [$page] = $this->load((int)$id, $lang);

        $rows = DB::proc('sp_revisions', [(int)$page['id'], $lang, 50]);
        json_out(['revisions' => array_map(static fn(array $r): array => [
            'id'      => (int)$r['id'],
            'author'  => $r['author'],
            'summary' => $r['summary'],
            'when'    => ago((string)$r['created_at']),
            'current' => (bool)$r['is_current'],
            'machine' => $r['source'] === 'machine',
            'size'    => (int)$r['size_chars'],
        ], $rows)]);
    }

    /**
     * GET /api/pages/{id}/diff?from={revId}&to={revId}
     *
     * `to` defaults to the current revision OF THE SAME LANGUAGE, which is what
     * "what changed since then" means in the history panel.
     */
    public function diff(string $id): void
    {
        $lang = $this->lang();
        [$page] = $this->load((int)$id, $lang);

        $from = DB::procOne('sp_revision_by_id', [(int)Request::query('from', '0')]);
        $toId = (int)Request::query('to', (string)(int)($page['current_revision_id'] ?? 0));
        $to   = DB::procOne('sp_revision_by_id', [$toId]);

        /* Both revisions must belong to THIS page and THIS language — otherwise
           the id in the query string is a way to read any revision of any page
           whose id you can guess, with only this page's permissions checked. */
        foreach ([$from, $to] as $r) {
            if (!$r || (int)$r['page_id'] !== (int)$page['id'] || $r['lang'] !== $lang) {
                throw new HttpError(404, 'No such revision.');
            }
        }

        $d = Diff::lines((string)$from['content_md'], (string)$to['content_md']);

        json_out([
            'added'     => $d['added'],
            'removed'   => $d['removed'],
            'truncated' => $d['truncated'],
            'rows'      => array_map(static fn(array $r): array => [
                't' => $r['type'],
                'o' => $r['old'],
                'n' => $r['new'],
                'x' => $r['text'],
            ], Diff::collapse($d['rows'])),
        ]);
    }

    /** GET /api/revisions/{id} — the Markdown of one revision, for diffing. */
    public function revision(string $id): void
    {
        $rev = DB::procOne('sp_revision_by_id', [(int)$id]);
        if (!$rev) throw new HttpError(404, 'No such revision.');

        $space = DB::procOne('sp_space_by_id', [(int)$rev['space_id'], Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such revision.');
        Perm::requireRead($space);
        Perm::requireWrite($space);

        json_out([
            'id'      => (int)$rev['id'],
            'lang'    => $rev['lang'],
            'title'   => $rev['title'],
            'content' => $rev['content_md'],
        ]);
    }

    /** POST /api/pages/{id}/revert/{revId} */
    public function revert(string $id, string $revId): void
    {
        $lang = $this->lang();
        [$page] = $this->load((int)$id, $lang);

        $rev = DB::procOne('sp_revision_by_id', [(int)$revId]);
        if (!$rev || (int)$rev['page_id'] !== (int)$page['id'] || $rev['lang'] !== $lang) {
            throw new HttpError(404, 'No such revision.');
        }

        /* Reverting APPENDS the old content as a new revision rather than
           deleting the ones after it. History stays a straight line, and an
           accidental revert is itself revertible. */
        $r = Markdown::render((string)$rev['content_md']);
        DB::proc('sp_revision_create', [
            (int)$page['id'], $lang, Auth::id(), (string)$rev['title'],
            (string)$rev['content_md'], $r['html'], $r['text'],
            'Reverted to revision #' . (int)$rev['id'], 'human', 0, I18n::defaultLang(),
        ]);

        json_out(['ok' => true, 'content' => $rev['content_md'], 'title' => $rev['title']]);
    }

    // -----------------------------------------------------------------------

    /**
     * The language being EDITED. Named by the request, defaulting to the site
     * default rather than to whatever the editor is browsing in — see the class
     * comment.
     */
    private function lang(): string
    {
        $l = Request::query('lang', '');
        if ($l === '') $l = (string)(Request::json()['lang'] ?? '');
        return I18n::isEnabled($l) ? $l : I18n::defaultLang();
    }

    /**
     * Load a page in one language, with its space, and assert write access.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function load(int $id, string $lang): array
    {
        $page = DB::procOne('sp_page_by_id', [$id, $lang]);
        if (!$page) throw new HttpError(404, 'No such page.');

        $space = DB::procOne('sp_space_by_id', [(int)$page['space_id'], Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such page.');

        Perm::requireRead($space);
        Perm::requireWrite($space);
        return [$page, $space];
    }

    /** Whether a non-public space may be sent to an external translation service. */
    private function mtAllowsPrivate(): bool
    {
        return \Core\Settings::get('mt_allow_private', '0') === '1';
    }
}
