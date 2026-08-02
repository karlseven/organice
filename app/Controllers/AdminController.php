<?php
declare(strict_types=1);

namespace Controllers;

use Core\Audit;
use Core\Brand;
use Core\Auth;
use Core\DB;
use Core\HttpError;
use Core\I18n;
use Core\Request;
use Core\Settings;
use Core\Slug;
use Core\View;

/** Site administration. The whole controller is behind the 'admin' access
 *  level in the route table, so no method re-checks it. */
final class AdminController
{
    public function index(): void
    {
        View::render('admin/spaces', [
            'title'  => 'Spaces · Admin',
            'spaces' => DB::proc('sp_spaces_visible', [Auth::id(), 1]),
            'flash'  => flash(),
        ]);
    }

    public function createSpace(): void
    {
        $title = Request::post('title');
        if ($title === '') throw new HttpError(422, 'A space needs a title.');

        $slug = Slug::make(Request::post('slug') ?: $title, 'space');

        /* Slug collisions are resolved rather than rejected: an admin creating
           "API" twice wants a second space, not an error dialog. */
        $existing = DB::proc('sp_spaces_visible', [Auth::id(), 1]);
        $slug = Slug::unique($slug, array_map(
            static fn(array $s): array => ['id' => 0, 'slug' => $s['slug']],
            $existing
        ));

        DB::proc('sp_space_create', [
            $slug,
            $title,
            Request::post('description'),
            $this->visibility(Request::post('visibility')),
            $this->accent(Request::post('accent')),
            Auth::id(),
        ]);

        Audit::log('space.create', 'space', 0, $title . ' (' . $slug . ')');
        flash('Space "' . $title . '" created.');
        redirect('/admin');
    }

    public function updateSpace(string $id): void
    {
        DB::exec('sp_space_update', [
            (int)$id,
            Request::post('title'),
            Request::post('description'),
            $this->visibility(Request::post('visibility')),
            $this->accent(Request::post('accent')),
        ]);
        Audit::log('space.update', 'space', (int)$id, Request::post('visibility'));
        flash('Space updated.');
        redirect('/admin');
    }

    public function deleteSpace(string $id): void
    {
        /* Cascades through pages, revisions, search rows and assets rows. The
           uploaded blobs on disk are deliberately left behind — they are
           content-addressed and may be shared with another space, and a
           sweeper is safer than a delete that runs inside a request. */
        $doomed = DB::procOne('sp_space_by_id', [(int)$id, Auth::id()]);
        Audit::log('space.delete', 'space', (int)$id, (string)($doomed['title'] ?? '?'));
        DB::exec('sp_space_delete', [(int)$id]);
        flash('Space deleted.', 'warn');
        redirect('/admin');
    }

    public function users(): void
    {
        View::render('admin/users', [
            'title' => 'Users · Admin',
            'users' => DB::proc('sp_users_all'),
            'flash' => flash(),
        ]);
    }

    public function createUser(): void
    {
        $email = Request::post('email');
        $pass  = (string)($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'That is not a valid email address.');
        }
        if (strlen($pass) < 10) {
            throw new HttpError(422, 'Passwords must be at least 10 characters.');
        }

        $display  = Request::post('display_name') ?: explode('@', $email)[0];
        $username = Slug::make(Request::post('username') ?: $display, 'user');

        try {
            DB::proc('sp_user_create', [
                $email, $username, $display, Auth::hash($pass), $this->role(Request::post('role')),
            ]);
        } catch (\PDOException $e) {
            // 23000 is the duplicate-key family: email or username already taken
            if ($e->getCode() === '23000') throw new HttpError(422, 'That email or username is already in use.');
            throw $e;
        }

        Audit::log('user.create', 'user', 0, $email . ' as ' . $this->role(Request::post('role')));
        flash('User created.');
        redirect('/admin/users');
    }

    public function updateUser(string $id): void
    {
        $uid = (int)$id;

        /* An admin must not be able to demote or deactivate themselves: doing
           so with the only admin account leaves the site with no way back in
           short of editing the database by hand. */
        if ($uid === Auth::id()) {
            throw new HttpError(422, 'You cannot change your own role or status.');
        }

        DB::exec('sp_user_update', [
            $uid,
            Request::post('display_name'),
            $this->role(Request::post('role')),
            Request::post('is_active') === '1' ? 1 : 0,
        ]);

        $pass = (string)($_POST['password'] ?? '');
        if ($pass !== '') {
            if (strlen($pass) < 10) throw new HttpError(422, 'Passwords must be at least 10 characters.');
            DB::exec('sp_user_set_password', [$uid, Auth::hash($pass)]);
        }

        Audit::log('user.update', 'user', $uid, 'role=' . $this->role(Request::post('role'))
            . ' active=' . Request::post('is_active') . ($pass !== '' ? ' password-reset' : ''));
        flash('User updated.');
        redirect('/admin/users');
    }

    /** GET /admin/spaces/{id}/members */
    public function members(string $id): void
    {
        $space = DB::procOne('sp_space_by_id', [(int)$id, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');

        $members = DB::proc('sp_space_members', [(int)$id]);
        $inSpace = array_column($members, 'user_id');

        View::render('admin/members', [
            'title'    => 'Members · ' . $space['title'],
            'space'    => $space,
            'members'  => $members,
            /* Only people who are not already members are offered in the add
               picker — otherwise the obvious way to change someone's role is to
               add them again, which reads as a mistake. */
            'addable'  => array_values(array_filter(
                DB::proc('sp_users_all'),
                static fn(array $u): bool => !in_array($u['id'], $inSpace, false)
            )),
            'flash'    => flash(),
        ]);
    }

    /** POST /admin/spaces/{id}/members — add, change role, or remove. */
    public function setMember(string $id): void
    {
        $space = DB::procOne('sp_space_by_id', [(int)$id, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');

        $userId = Request::postInt('user_id');
        if ($userId <= 0) throw new HttpError(422, 'Pick a user.');

        // an empty role is the remove signal — see sp_space_member_set
        $role = Request::post('role');
        if ($role !== '' && !in_array($role, ['owner', 'editor', 'viewer'], true)) {
            throw new HttpError(422, 'That is not a valid role.');
        }

        DB::exec('sp_space_member_set', [(int)$id, $userId, $role]);
        Audit::log(
            $role === '' ? 'space.member.remove' : 'space.member.set',
            'space', (int)$id, 'user=' . $userId . ($role !== '' ? ' role=' . $role : '')
        );

        flash($role === '' ? 'Member removed.' : 'Member saved.');
        redirect('/admin/spaces/' . (int)$id . '/members');
    }

    /** GET /admin/settings */
    public function settings(): void
    {
        View::render('admin/settings', [
            'title'  => 'Settings · Admin',
            'spaces' => DB::proc('sp_spaces_visible', [Auth::id(), 1]),
            'flash'  => flash(),
        ]);
    }

    /**
     * POST /admin/settings
     *
     * Every value is validated here rather than trusted, because these settings
     * are interpolated into pages (the accent goes into CSS, the mode decides
     * routing) and "an admin typed it" is not a safety argument — an admin
     * account is exactly what an attacker wants.
     */
    public function saveSettings(): void
    {
        // ---- identity ----
        Settings::set('site_title',   mb_substr(Request::post('site_title'), 0, 120) ?: 'Organice');
        Settings::set('site_tagline', mb_substr(Request::post('site_tagline'), 0, 200));
        Settings::set('brand_footer', mb_substr(Request::post('brand_footer'), 0, 300));

        // ---- mode ----
        $mode = Request::post('site_mode') === 'single' ? 'single' : 'multi';
        $single = Request::post('single_space');

        $slugs = array_column(DB::proc('sp_spaces_visible', [Auth::id(), 1]), 'slug');
        if (!in_array($single, $slugs, true)) $single = '';

        /* Single mode without a space would take every URL to a 404, so it is
           refused rather than saved and puzzled over later. */
        if ($mode === 'single' && $single === '') {
            throw new HttpError(422, 'Choose which space the site should be before switching to single-space mode.');
        }
        Settings::set('site_mode', $mode);
        Settings::set('single_space', $single);

        // ---- languages ----
        $default = Request::post('default_lang');
        if (!isset(I18n::LANGUAGES[$default])) $default = 'en';

        $enabled = [];
        foreach ((array)($_POST['languages'] ?? []) as $code) {
            if (is_string($code) && isset(I18n::LANGUAGES[$code])) $enabled[] = $code;
        }
        // the default language is always on, whatever the checkboxes said
        if (!in_array($default, $enabled, true)) array_unshift($enabled, $default);

        Settings::set('default_lang', $default);
        Settings::set('languages', implode(',', array_unique($enabled)));

        // ---- branding ----
        $accent = Request::post('brand_accent');
        Settings::set('brand_accent', preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? strtolower($accent) : '');

        foreach (Brand::imageKeys() as $key) {
            if (Request::post('clear_' . $key) === '1') {
                Brand::forget($key);
                Settings::set($key, '');
                continue;
            }
            $up = $_FILES[$key] ?? null;
            if (is_array($up) && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                Settings::set($key, Brand::store($up, $key));
            }
        }

        // ---- machine translation ----
        $driver = Request::post('mt_driver');
        Settings::set('mt_driver', in_array($driver, ['google', 'libretranslate'], true) ? $driver : '');
        Settings::set('mt_allow_private', Request::post('mt_allow_private') === '1' ? '1' : '0');

        Audit::log('settings.update', 'site', 0, 'mode=' . $mode . ' lang=' . $default);
        flash('Settings saved.');
        redirect('/admin/settings');
    }

    /** GET /admin/audit */
    public function audit(): void
    {
        View::render('admin/audit', [
            'title'   => 'Audit log · Admin',
            'entries' => DB::proc('sp_audit_recent', [200]),
        ]);
    }

    // ---- input normalisation: the ENUM columns reject anything else, and a
    //      raw SQL error is not a useful thing to show an admin ----------------

    private function visibility(string $v): string
    {
        return in_array($v, ['public', 'internal', 'private'], true) ? $v : 'public';
    }

    private function role(string $v): string
    {
        return in_array($v, ['admin', 'editor', 'viewer'], true) ? $v : 'viewer';
    }

    private function accent(string $v): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : '#5b7cfa';
    }
}
