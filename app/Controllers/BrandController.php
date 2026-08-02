<?php
declare(strict_types=1);

namespace Controllers;

use Core\Brand;
use Core\HttpError;

final class BrandController
{
    /**
     * GET /brand/{file} — the site logo or favicon.
     *
     * Served through the app rather than from public/, so nothing an admin
     * uploads ever lands somewhere the web server would execute. Public by
     * definition: it is the site's branding, shown to everyone, so there is no
     * permission check here.
     */
    public function show(string $file): void
    {
        $path = Brand::path($file);
        if ($path === null || !is_file($path)) throw new HttpError(404, 'No such image.');

        $mime = Brand::mime($file);

        /* The filename carries a content hash, so these bytes can never change
           under this URL — cache it hard, and publicly, unlike page HTML. */
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');

        // an SVG opened directly is a document in this origin; sandbox it
        if ($mime === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        readfile($path);
        exit;
    }
}
