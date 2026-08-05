<?php
declare(strict_types=1);

namespace Controllers;

use Core\Auth;
use Core\DB;
use Core\HttpError;
use Core\Perm;
use Core\Uploader;

final class AssetController
{
    /** GET /file/{sha256}/{filename} */
    public function show(string $sha, string $filename): void
    {
        $asset = DB::procOne('sp_asset_by_sha', [$sha]);
        if (!$asset) throw new HttpError(404, 'No such file.');

        /* The file is only served if the space it belongs to is readable —
           otherwise a private space's screenshots are public to anyone who
           learns the URL, which is exactly what storing them under a hash
           would otherwise tempt you to assume is enough. */
        $space = DB::procOne('sp_space_by_id', [(int)$asset['space_id'], Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such file.');
        Perm::requireRead($space);

        $path = Uploader::path($sha);
        if (!is_file($path)) throw new HttpError(404, 'No such file.');

        $mime = (string)$asset['mime'];

        /* The URL contains the content hash, so these bytes can never change
           under this URL — safe to cache hard. `private` because the space may
           be non-public and a shared proxy must not keep a copy. */
        header('Cache-Control: private, max-age=31536000, immutable');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');

        /* SVG opened directly is a document in this origin and could run script
           it carries. Sandboxing it costs nothing for the <img> case, which is
           how it is actually used, and removes that entirely. */
        if ($mime === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        // never let the browser decide to render an upload inline as a document
        header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.-]/', '_', $filename) . '"');

        readfile($path);
        exit;
    }

    /** POST /api/upload (multipart) — from the editor's paste/drop handler. */
    public function upload(): void
    {
        $spaceId = (int)($_POST['space_id'] ?? 0);
        $space = DB::procOne('sp_space_by_id', [$spaceId, Auth::id()]);
        if (!$space) throw new HttpError(404, 'No such space.');
        Perm::requireRead($space);
        Perm::requireWrite($space);

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpError(400, 'No file was sent.');
        }

        $r = Uploader::store($_FILES['file'], $spaceId);

        json_out([
            'id'       => $r['id'],
            'url'      => url('/file/' . $r['sha'] . '/' . rawurlencode($r['filename'])),
            'filename' => $r['filename'],
            'mime'     => $r['mime'],
            // ready to paste straight into the Markdown pane
            'markdown' => (str_starts_with($r['mime'], 'image/') ? '!' : '')
                        . '[' . $r['filename'] . '](' . url('/file/' . $r['sha'] . '/' . rawurlencode($r['filename'])) . ')',
        ], 201);
    }
}
