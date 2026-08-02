<?php
declare(strict_types=1);

namespace Core;

/**
 * Image and file uploads for the editor.
 *
 * Files are stored OUTSIDE the webroot (storage/uploads) under their sha256,
 * fanned out two levels so no directory ends up with a hundred thousand
 * entries. Nothing under storage/ is reachable by URL, so a file that turns
 * out to be a PHP script in an image's clothing is inert — AssetController
 * reads it and sends it back with a fixed Content-Type.
 */
final class Uploader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** mime => extension. An allow-list: anything not named here is refused. */
    private const ALLOWED = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{sha:string,filename:string,mime:string,size:int}
     */
    public static function store(array $file, int $spaceId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError(400, 'Upload failed.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new HttpError(413, 'Files are limited to 10 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new HttpError(400, 'Upload failed.');
        }

        /* The browser-supplied type is ignored entirely — it is author input.
           finfo reads the actual bytes. */
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new HttpError(415, 'That file type is not allowed.');
        }

        /* SVG is an allowed image AND a scripting vector: it can carry <script>
           and event handlers that run in the site's origin when opened
           directly. AssetController always sends it with a sandboxing CSP and
           as an attachment-safe type, so it renders in an <img> (where script
           never runs) but cannot execute if someone opens the URL. */

        $sha = hash_file('sha256', $file['tmp_name']);
        $dir = UPLOAD_PATH . '/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError(500, 'Could not create the upload directory.');
        }

        $dest = $dir . '/' . $sha;
        // identical bytes are already there — dedup, and do not rewrite the file
        if (!is_file($dest) && !move_uploaded_file($file['tmp_name'], $dest)) {
            throw new HttpError(500, 'Could not save the upload.');
        }

        $name = self::safeName((string)($file['name'] ?? 'file'), self::ALLOWED[$mime]);

        DB::proc('sp_asset_create', [$spaceId, $sha, $name, $mime, (int)$file['size'], Auth::id()]);

        return ['sha' => $sha, 'filename' => $name, 'mime' => $mime, 'size' => (int)$file['size']];
    }

    /** Absolute path of a stored blob. */
    public static function path(string $sha): string
    {
        return UPLOAD_PATH . '/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
    }

    /**
     * The original name is kept only for the download filename and the editor's
     * alt text; it never touches the filesystem path, so traversal and odd
     * characters are a display problem here, not a security one.
     */
    private static function safeName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\w.-]+/u', '-', $base) ?? 'file';
        $base = trim(substr($base, 0, 80), '-.');
        return ($base === '' ? 'file' : $base) . '.' . $ext;
    }
}
