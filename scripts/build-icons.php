<?php
declare(strict_types=1);

/**
 * Regenerate the bundled Lucide icon set.
 *
 *   php scripts/build-icons.php                  download the pinned version
 *   php scripts/build-icons.php --src=path/to/lucide-static/package
 *
 * Writes three generated files, all of which ARE committed — the app must work
 * from a clean checkout with no network, no npm and no build step, which is the
 * whole point of the house style:
 *
 *   app/Data/lucide-icons.php            name => inner SVG markup (server-side)
 *   public/assets/icons/lucide.svg       <symbol> sprite, for the picker only
 *   public/assets/icons/lucide-index.json  names + search tags, for the picker
 *
 * Why both a PHP map and a sprite, rather than one of them:
 *
 *   Readers get icons INLINED from the PHP map. A page shows perhaps thirty
 *   distinct icons, which is ~6 KB of markup — far less than making every
 *   reader fetch a 350 KB sprite to use 1.5% of it. HTML here is sent
 *   `no-store` (it carries a CSP nonce and the signed-in user), so a sprite
 *   would not amortise the way it does on a cacheable site.
 *
 *   The PICKER genuinely needs all 2000 at once, and only signed-in authors
 *   ever open it. That is the one place where a single cacheable sprite is the
 *   right trade, so it is loaded there and nowhere else.
 */

/* PHP 8 string helpers, for 7.4. This script deliberately does not
   bootstrap the application, so it loads them directly. */
require __DIR__ . "/../app/polyfill.php";

const LUCIDE_VERSION = '1.28.0';

$root = dirname(__DIR__);
$src  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--src=')) $src = rtrim(substr($a, 6), '/\\');
}

/* ---- obtain the source ---------------------------------------------- */
$tmp = null;
if ($src === null) {
    $url = 'https://registry.npmjs.org/lucide-static/-/lucide-static-' . LUCIDE_VERSION . '.tgz';
    fwrite(STDERR, "downloading $url\n");
    $tgz = @file_get_contents($url);
    if ($tgz === false) {
        fwrite(STDERR, "download failed. Fetch the tarball yourself and pass --src=<extracted>/package\n");
        exit(1);
    }
    $tmp = sys_get_temp_dir() . '/lucide-' . LUCIDE_VERSION;
    if (!is_dir($tmp)) mkdir($tmp, 0777, true);
    file_put_contents("$tmp/l.tgz", $tgz);
    /* PharData rather than shelling out to tar: this has to run on Windows,
       where tar is not a given. */
    $phar = new PharData("$tmp/l.tgz");
    $phar->decompress();
    (new PharData("$tmp/l.tar"))->extractTo($tmp, null, true);
    $src = "$tmp/package";
}

if (!is_dir("$src/icons")) {
    fwrite(STDERR, "no icons/ directory under $src\n");
    exit(1);
}

/* ---- read the icons -------------------------------------------------- */
$icons = [];
foreach (glob("$src/icons/*.svg") as $file) {
    $name = basename($file, '.svg');
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        fwrite(STDERR, "skipping unexpected icon name: $name\n");
        continue;
    }
    $svg = file_get_contents($file);

    /* Keep only what is inside <svg>. The wrapper is rebuilt at render time so
       that size, stroke width and accessibility attributes are decided by the
       app, not frozen into 2000 copies of the same boilerplate. */
    $inner = preg_replace('#^.*?<svg\b[^>]*>#s', '', $svg);
    $inner = preg_replace('#</svg>\s*$#', '', (string)$inner);
    $inner = trim(preg_replace('/\s+/', ' ', (string)$inner));
    $inner = str_replace('> <', '><', $inner);

    /* These files are vendored input, but they are still input: anything that
       is not a plain shape element gets rejected rather than trusted. A
       <script> or an onload= reaching the markup map would be injected into
       every page that used that icon, and no output escaping would catch it
       because this markup is deliberately printed raw. */
    if (preg_match('/<(?!\/?(?:path|circle|rect|line|polyline|polygon|ellipse|g|defs|clipPath|mask|use)\b)/i', $inner)) {
        fwrite(STDERR, "REJECTED $name: unexpected element\n");
        continue;
    }
    if (preg_match('/\son[a-z]+\s*=|javascript:/i', $inner)) {
        fwrite(STDERR, "REJECTED $name: script-ish attribute\n");
        continue;
    }

    $icons[$name] = $inner;
}
ksort($icons);
if (count($icons) < 1000) {
    fwrite(STDERR, 'only ' . count($icons) . " icons found — refusing to write a truncated set\n");
    exit(1);
}

/* ---- search tags ----------------------------------------------------- */
$tags = [];
if (is_file("$src/tags.json")) {
    $tags = json_decode((string)file_get_contents("$src/tags.json"), true) ?: [];
}

$index = [];
foreach ($icons as $name => $_) {
    /* The words of the name are searchable too, so "arrow" finds arrow-up
       without every icon needing an explicit tag for its own name. */
    $words = array_values(array_unique(array_merge(
        explode('-', $name),
        array_map(static fn($t) => (string)$t, $tags[$name] ?? [])
    )));
    $index[$name] = implode(' ', $words);
}

/* ---- write the PHP map ----------------------------------------------- */
$header = "<?php\n"
    . "/* GENERATED by scripts/build-icons.php — do not edit by hand.\n"
    . " * Lucide v" . LUCIDE_VERSION . ", ISC licence.\n"
    . " * Copyright (c) Lucide Contributors — see docs/CREDITS.md.\n"
    . " */\n";

$php = $header . "return [\n";
foreach ($icons as $name => $inner) {
    $php .= "    '" . $name . "' => '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $inner) . "',\n";
}
$php .= "];\n";

if (!is_dir("$root/app/Data")) mkdir("$root/app/Data", 0777, true);
file_put_contents("$root/app/Data/lucide-icons.php", $php);

/* ---- write the sprite ------------------------------------------------ */
$sprite = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<!-- GENERATED by scripts/build-icons.php. Lucide v' . LUCIDE_VERSION . ', ISC. -->' . "\n"
    . '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">' . "\n";
foreach ($icons as $name => $inner) {
    /* fill/stroke live on the symbol so a bare <use> renders correctly without
       the caller having to restate them. */
    /* 1.8 rather than Lucide's native 2: it matches the stroke weight the rest
       of this UI already draws at, and the picker must preview an icon exactly
       as the page will render it — "it looked thinner in the picker" is a real
       complaint, not a nitpick. Core\Icon uses the same figure. */
    $sprite .= '<symbol id="i-' . $name . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $inner . "</symbol>\n";
}
$sprite .= "</svg>\n";

if (!is_dir("$root/public/assets/icons")) mkdir("$root/public/assets/icons", 0777, true);
file_put_contents("$root/public/assets/icons/lucide.svg", $sprite);
file_put_contents(
    "$root/public/assets/icons/lucide-index.json",
    json_encode($index, JSON_UNESCAPED_SLASHES)
);

/* ---- report ---------------------------------------------------------- */
printf(
    "wrote %d icons\n  app/Data/lucide-icons.php          %s KB\n"
    . "  public/assets/icons/lucide.svg      %s KB\n"
    . "  public/assets/icons/lucide-index.json %s KB\n",
    count($icons),
    number_format(filesize("$root/app/Data/lucide-icons.php") / 1024, 1),
    number_format(filesize("$root/public/assets/icons/lucide.svg") / 1024, 1),
    number_format(filesize("$root/public/assets/icons/lucide-index.json") / 1024, 1)
);

/* Carry the licence into the tree — ISC requires the notice to travel with the
   copies, and "we downloaded it once" is not a notice. */
if (is_file("$src/LICENSE")) {
    copy("$src/LICENSE", "$root/public/assets/icons/LICENSE-lucide.txt");
    echo "  public/assets/icons/LICENSE-lucide.txt\n";
}
