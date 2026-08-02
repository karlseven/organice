<?php
declare(strict_types=1);

namespace Controllers;

use Core\Auth;
use Core\DB;
use Core\HttpError;
use Core\I18n;
use Core\Perm;
use Core\Site;
use Core\Settings;
use Core\View;

final class SpaceController
{
    /** The site home: every space the visitor is allowed to see. */
    public function index(): void
    {
        $spaces = DB::proc('sp_spaces_visible', [Auth::id(), Auth::isAdmin() ? 1 : 0]);

        /* With one space configured as the home, the landing page is that
           book's first page — a single-product site should not make readers
           click through a list of one. */
        /* Single mode has no space list at all: the site IS one book, so the
           home page is that book's first page. */
        if (Site::isSingle()) {
            $this->show(Site::singleSlug());
            return;
        }

        $home = Settings::get('home_space');
        if ($home !== '' && count($spaces) === 1 && $spaces[0]['slug'] === $home) {
            $this->show($home);
            return;
        }

        View::render('spaces/index', [
            'title'  => Settings::get('site_title', SITE_NAME),
            'spaces' => $spaces,
        ]);
    }

    /** A space's own URL sends the reader to its first page. */
    public function show(string $slug): void
    {
        $space = DB::procOne('sp_space_by_slug', [$slug, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');
        Perm::requireRead($space);

        $first = DB::procOne('sp_page_first', [
            (int)$space['id'],
            Perm::seesDrafts($space) ? 1 : 0,
        ]);

        if (!$first) {
            // An empty book is a normal state, not an error — an editor lands
            // here right after creating one.
            View::render('spaces/empty', [
                'title' => $space['title'],
                'space' => $space,
                'canEdit' => Perm::canWrite($space),
            ]);
            return;
        }

        header('Location: ' . Site::pageUrl((string)$space['slug'], (string)$first['path']), true, 302);
        exit;
    }
}
