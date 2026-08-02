<?php
declare(strict_types=1);

namespace Core;

/**
 * White-labelling: the site's logo, favicon and accent colour.
 *
 * Brand images are stored in `storage/brand/`, OUTSIDE the webroot, and served
 * by BrandController. Writing them into `public/` would be simpler and is what
 * most projects do, but it means the web server executes whatever ends up
 * there — and this is an admin-facing upload form, which is exactly the shape
 * of a "upload a .php disguised as a .png" problem. Serving through the app
 * costs one route and removes the question entirely.
 *
 * The stored setting holds a FILENAME, not a URL, so moving the site or
 * changing APP_BASE does not strand the logo.
 */
final class Brand
{
    private const DIR = 'brand';

    /** mime => extension. Same allow-list discipline as Core\Uploader. */
    private const ALLOWED = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon'  => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    /** @return array<int,string> the settings keys that hold an image */
    public static function imageKeys(): array
    {
        return ['brand_logo', 'brand_logo_dark', 'brand_favicon'];
    }

    public static function dir(): string
    {
        return BASE_PATH . '/storage/' . self::DIR;
    }

    /** Absolute path of a stored brand file, or null if the name is not ours. */
    public static function path(string $file): ?string
    {
        /* The name must match exactly what store() writes. Anything else is
           refused rather than sanitised — there is no legitimate caller that
           needs a different shape, and a traversal attempt should not be
           quietly turned into a valid path. */
        if (!preg_match('/^(logo|logo-dark|favicon)-[a-f0-9]{8}\.(png|jpg|webp|svg|ico)$/', $file)) {
            return null;
        }
        return self::dir() . '/' . $file;
    }

    /**
     * The URL for a brand image, or '' when that slot is empty.
     * The filename carries a content hash, so it can be cached hard.
     */
    public static function url(string $key): string
    {
        $file = Settings::get($key, '');
        if ($file === '' || self::path($file) === null) return '';
        return url('/brand/' . $file);
    }

    public static function has(string $key): bool
    {
        return self::url($key) !== '';
    }

    /**
     * Store an uploaded brand image and return the filename to save in settings.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public static function store(array $file, string $key): string
    {
        if (!in_array($key, self::imageKeys(), true)) {
            throw new HttpError(422, 'Unknown brand image.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError(400, 'Upload failed.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new HttpError(413, 'Brand images are limited to 2 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new HttpError(400, 'Upload failed.');
        }

        // the bytes decide the type, never the browser-supplied header
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new HttpError(415, 'Use a PNG, JPEG, WebP, SVG or ICO.');
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError(500, 'Could not create the brand directory.');
        }

        $slot = ['brand_logo' => 'logo', 'brand_logo_dark' => 'logo-dark', 'brand_favicon' => 'favicon'][$key];

        /* A short content hash in the name means the URL changes whenever the
           image does, so the one-year cache header is safe and a replaced logo
           is never served stale. */
        $stamp = substr(hash_file('sha256', $file['tmp_name']), 0, 8);
        $name  = $slot . '-' . $stamp . '.' . self::ALLOWED[$mime];

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new HttpError(500, 'Could not save the image.');
        }

        self::forget($key);          // drop the previous file for this slot
        return $name;
    }

    /** Delete the file currently held in a slot, leaving the setting alone. */
    public static function forget(string $key): void
    {
        $old = Settings::get($key, '');
        if ($old === '') return;
        $p = self::path($old);
        if ($p !== null && is_file($p)) @unlink($p);
    }

    public static function mime(string $file): string
    {
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        return [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        ][$ext] ?? 'application/octet-stream';
    }

    /**
     * The accent colour override, or '' to keep the built-in one.
     * Validated here because it is interpolated into a CSS custom property.
     */
    public static function accent(): string
    {
        $v = Settings::get('brand_accent', '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : '';
    }
}
