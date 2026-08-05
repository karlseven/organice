<?php
declare(strict_types=1);

namespace Controllers;

use Core\Audit;
use Core\Auth;
use Core\DB;
use Core\HttpError;
use Core\Perm;
use Core\Request;
use Core\Site;
use Core\Uploader;
use Core\View;

/**
 * The media library: browse, organise and reuse what has been uploaded.
 *
 * Folders are virtual — a label on the `assets` row, not a directory. The bytes
 * are content-addressed under storage/uploads and never move, which is what
 * makes reorganising the library a single UPDATE that cannot break a page
 * already pointing at a file.
 *
 * Everything here is scoped to ONE space, matching how uploads already work.
 * A private space's images must not be listable — let alone insertable — from
 * a space someone happens to have write access to.
 */
final class MediaController
{
    /**
     * The space being browsed, with permission already checked.
     *
     * @return array<string,mixed>
     */
    private function space(int $id, bool $write = false): array
    {
        $space = DB::procOne('sp_space_by_id', [$id, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');
        Perm::requireRead($space);
        if ($write) Perm::requireWrite($space);
        return $space;
    }

    /** GET /media — the standalone library page. */
    public function index(): void
    {
        /* Only spaces this person may WRITE. The library is an authoring tool;
           listing a space you can read but not edit would offer a folder tree
           with every action in it disabled. */
        $spaces = array_values(array_filter(
            DB::proc('sp_spaces_visible', [Auth::id(), Auth::isAdmin() ? 1 : 0]),
            static function (array $s): bool { return Perm::canWrite($s); }
        ));
        if ($spaces === []) throw new HttpError(403, 'You cannot edit any space.');

        $wanted = (int)($_GET['space'] ?? 0);
        $space  = null;
        foreach ($spaces as $s) {
            if ((int)$s['id'] === $wanted) { $space = $s; break; }
        }
        if (!$space) $space = $spaces[0];

        /* ?partial=1 returns the library markup ALONE, for the editor's picker
           dialog. The alternative was a second copy of this markup written in
           JavaScript — one grid, one folder tree and one set of translated
           labels, maintained twice. Nothing sensitive is exposed by it: the
           partial is the same empty shell the page renders, and every byte of
           content still arrives through /api/media, which checks the space. */
        if (($_GET['partial'] ?? '') === '1') {
            header('Content-Type: text/html; charset=utf-8');
            $picker = true;
            require APP_PATH . '/Views/partials/media-library.php';
            exit;
        }

        View::render('media/index', [
            'title'      => t('media.title'),
            'spaces'     => $spaces,
            'space'      => $space,
            'needsMedia' => true,
        ]);
    }

    /** GET /api/media?space=&folder=&q= — one folder's worth of assets. */
    public function list(): void
    {
        $space  = $this->space((int)($_GET['space'] ?? 0));
        $folder = Uploader::folder((string)($_GET['folder'] ?? ''));
        $q      = trim((string)($_GET['q'] ?? ''));

        $rows = DB::proc('sp_assets_list', [(int)$space['id'], $folder, $q]);

        $items = [];
        foreach ($rows as $r) {
            $url = url('/file/' . $r['sha256'] . '/' . rawurlencode((string)$r['filename']));
            $isImage = strpos((string)$r['mime'], 'image/') === 0;
            $items[] = [
                'id'       => (int)$r['id'],
                'sha'      => $r['sha256'],
                'name'     => $r['filename'],
                'mime'     => $r['mime'],
                'size'     => (int)$r['size_bytes'],
                'folder'   => $r['folder'],
                'url'      => $url,
                'image'    => $isImage,
                'created'  => $r['created_at'],
                'uploader' => $r['uploader'],
                /* Built here rather than in JavaScript so the editor and the
                   standalone page insert byte-identical Markdown. */
                'markdown' => ($isImage ? '!' : '') . '[' . $r['filename'] . '](' . $url . ')',
            ];
        }

        json_out([
            'ok'      => true,
            'folder'  => $folder,
            'items'   => $items,
            'folders' => $this->folderTree((int)$space['id'], $folder),
        ]);
    }

    /**
     * Immediate subfolders of $under, with a count of what is directly inside.
     *
     * A folder exists only because rows carry its name, so this derives the
     * tree from those labels. An "empty" folder therefore cannot persist on its
     * own — the UI keeps a freshly created one visible client-side until
     * something is moved into it, which is honest: nothing has been stored yet.
     *
     * @return array<int,array<string,mixed>>
     */
    private function folderTree(int $spaceId, string $under): array
    {
        $prefix = $under === '' ? '' : $under . '/';
        $direct = [];

        foreach (DB::proc('sp_asset_folders', [$spaceId]) as $row) {
            $folder = (string)$row['folder'];
            if ($folder === '' || $folder === $under) continue;
            if ($prefix !== '' && strpos($folder, $prefix) !== 0) continue;

            $rest = substr($folder, strlen($prefix));
            $name = strpos($rest, '/') === false ? $rest : substr($rest, 0, strpos($rest, '/'));
            if ($name === '') continue;

            $path = $prefix . $name;
            /* Counts every asset at or below the child, which is what someone
               reading a folder list expects — a folder showing "0" because its
               contents are one level deeper looks broken. */
            $direct[$path] = ($direct[$path] ?? 0) + (int)$row['n'];
        }

        ksort($direct);
        $out = [];
        foreach ($direct as $path => $n) {
            $out[] = ['path' => $path, 'name' => basename($path), 'count' => $n];
        }
        return $out;
    }

    /** POST /api/media/{id}/move — put one asset in a folder. */
    public function move(string $id): void
    {
        $in     = Request::json();
        $space  = $this->space((int)($in['space_id'] ?? 0), true);
        $asset  = $this->ownedAsset((int)$id, (int)$space['id']);
        $folder = Uploader::folder((string)($in['folder'] ?? ''));

        DB::exec('sp_asset_move', [(int)$asset['id'], (int)$space['id'], $folder]);
        json_out(['ok' => true, 'folder' => $folder]);
    }

    /** POST /api/media/{id}/rename — change the display filename. */
    public function rename(string $id): void
    {
        $in    = Request::json();
        $space = $this->space((int)($in['space_id'] ?? 0), true);
        $asset = $this->ownedAsset((int)$id, (int)$space['id']);

        /* The extension is preserved from the stored name rather than taken
           from the input: the file is served with the MIME type recorded at
           upload, and a name claiming .html over image/png is a confusing lie
           at best. */
        $ext  = pathinfo((string)$asset['filename'], PATHINFO_EXTENSION);
        $base = pathinfo(trim((string)($in['name'] ?? '')), PATHINFO_FILENAME);
        $base = preg_replace('/[^\w .()-]+/u', '-', $base) ?? '';
        $base = trim(substr($base, 0, 80), '-. ');
        if ($base === '') throw new HttpError(422, 'That name is empty.');

        $name = $base . ($ext !== '' ? '.' . $ext : '');
        DB::exec('sp_asset_rename', [(int)$asset['id'], (int)$space['id'], $name]);
        json_out(['ok' => true, 'name' => $name]);
    }

    /** GET /api/media/{id}/usage — which pages currently reference this file. */
    public function usage(string $id): void
    {
        $space = $this->space((int)($_GET['space'] ?? 0));
        $asset = $this->ownedAsset((int)$id, (int)$space['id']);

        $pages = [];
        foreach (DB::proc('sp_asset_usage', [(int)$space['id'], (string)$asset['sha256']]) as $p) {
            $pages[] = [
                'title' => $p['title'],
                'url'   => Site::pageUrl((string)$space['slug'], (string)$p['path']),
            ];
        }
        json_out(['ok' => true, 'pages' => $pages]);
    }

    /**
     * POST /api/media/{id}/delete — forget an upload.
     *
     * Removes the `assets` row only. The blob stays on disk for
     * scripts/gc-assets.php to sweep, because the same bytes may be referenced
     * by an older revision of some page, and deleting them here would rewrite
     * history that is supposed to be immutable.
     */
    public function delete(string $id): void
    {
        $in    = Request::json();
        $space = $this->space((int)($in['space_id'] ?? 0), true);
        $asset = $this->ownedAsset((int)$id, (int)$space['id']);

        Audit::log('asset.delete', 'asset', (int)$asset['id'], (string)$asset['filename']);
        DB::exec('sp_asset_delete', [(int)$asset['id']]);
        json_out(['ok' => true]);
    }

    /** POST /api/media/folder — rename a folder and everything under it. */
    public function renameFolder(): void
    {
        $in    = Request::json();
        $space = $this->space((int)($in['space_id'] ?? 0), true);

        $from = Uploader::folder((string)($in['from'] ?? ''));
        $to   = Uploader::folder((string)($in['to'] ?? ''));
        if ($from === '' || $to === '') throw new HttpError(422, 'A folder needs a name.');

        /* Moving a folder inside itself would orphan every row under it: the
           first UPDATE renames 'a' to 'a/b', and the second then tries to
           re-prefix rows that have already moved. */
        if ($to === $from || strpos($to, $from . '/') === 0) {
            throw new HttpError(422, 'A folder cannot be moved inside itself.');
        }

        DB::exec('sp_asset_folder_rename', [(int)$space['id'], $from, $to]);
        json_out(['ok' => true, 'folder' => $to]);
    }

    /**
     * An asset that really belongs to this space.
     *
     * The id arrives in a request body. Without this check, someone who can
     * write to any space could move, rename or delete rows belonging to a
     * private space they cannot read.
     *
     * @return array<string,mixed>
     */
    private function ownedAsset(int $id, int $spaceId): array
    {
        $asset = DB::procOne('sp_asset_by_id', [$id]);
        if (!$asset || (int)$asset['space_id'] !== $spaceId) {
            throw new HttpError(404, 'No such file.');
        }
        return $asset;
    }
}
