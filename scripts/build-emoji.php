<?php
declare(strict_types=1);

/**
 * Regenerate the bundled emoji list.
 *
 *   php scripts/build-emoji.php                 download the pinned version
 *   php scripts/build-emoji.php --src=data.json
 *
 * Writes one committed file:
 *
 *   public/assets/icons/emoji.json   [[emoji, "label and search words"], …]
 *
 * Source is emojibase (MIT), which is CLDR's emoji annotations packaged as
 * JSON — the same names and keywords a phone keyboard searches.
 *
 * The list is FLAT and in Unicode order. That ordering is not arbitrary: it
 * runs smileys → people → animals → food → travel → activities → objects →
 * symbols → flags, so the picker still reads in a familiar sequence without
 * needing category headings.
 */

/* PHP 8 string helpers, for 7.4. This script deliberately does not
   bootstrap the application, so it loads them directly. */
require __DIR__ . "/../app/polyfill.php";

const EMOJIBASE_VERSION = '17.0.0';

/**
 * Emoji newer than this are left out.
 *
 * Not squeamishness about new emoji — a picker that offers a character the
 * reader's system has no glyph for shows an empty box, and a grid of boxes
 * looks like the feature is broken rather than like the font is old. Emoji 16
 * and 17 (16 characters between them) have essentially no OS coverage today.
 * Raise this as fonts catch up.
 */
const MAX_EMOJI_VERSION = 15.1;

$root = dirname(__DIR__);
$src  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--src=')) $src = substr($a, 6);
}

if ($src === null) {
    /* The single file, not the 48 MB package: every other language's copy of
       the annotations is weight this build does not need. */
    $url = 'https://unpkg.com/emojibase-data@' . EMOJIBASE_VERSION . '/en/data.json';
    fwrite(STDERR, "downloading $url\n");
    $json = @file_get_contents($url);
    if ($json === false) {
        fwrite(STDERR, "download failed. Fetch it yourself and pass --src=<file>\n");
        exit(1);
    }
} else {
    $json = file_get_contents($src);
    if ($json === false) { fwrite(STDERR, "cannot read $src\n"); exit(1); }
}

$data = json_decode($json, true);
if (!is_array($data)) { fwrite(STDERR, "source is not JSON\n"); exit(1); }

$rows = [];
foreach ($data as $e) {
    /* No `group` means a COMPONENT — a lone skin-tone modifier or a single
       regional-indicator letter. They are building blocks, not things anyone
       picks: 🇦 on its own is a letter A in a box, not a flag. */
    if (!isset($e['group'])) continue;

    if ((float)($e['version'] ?? 0) > MAX_EMOJI_VERSION) continue;

    $char = (string)($e['emoji'] ?? '');
    if ($char === '') continue;

    $label = (string)($e['label'] ?? '');
    $words = array_merge(
        preg_split('/[^a-z0-9]+/i', strtolower($label)) ?: [],
        array_map('strval', $e['tags'] ?? [])
    );
    /* The label's own words are included so "rocket" matches 🚀 by name, not
       only by whichever tags CLDR happened to give it. */
    $words = array_values(array_unique(array_filter($words, static fn($w) => $w !== '')));

    $rows[] = [
        'order' => (int)($e['order'] ?? PHP_INT_MAX),
        'char'  => $char,
        'label' => $label,
        'terms' => implode(' ', $words),
    ];
}

usort($rows, static fn($a, $b) => $a['order'] <=> $b['order']);

if (count($rows) < 1000) {
    fwrite(STDERR, 'only ' . count($rows) . " emoji — refusing to write a truncated set\n");
    exit(1);
}

/* [char, terms] pairs. The label is the first word-run of `terms`, so it is not
   stored twice — the picker takes the accessible name from the row it already
   has rather than shipping a third column. */
$out = [];
foreach ($rows as $r) {
    $out[] = [$r['char'], $r['label'], $r['terms']];
}

$dir = "$root/public/assets/icons";
if (!is_dir($dir)) mkdir($dir, 0777, true);
file_put_contents("$dir/emoji.json", json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

printf(
    "wrote %d emoji (cutoff: Emoji %s)\n  %s   %s KB\n",
    count($out),
    MAX_EMOJI_VERSION,
    "public/assets/icons/emoji.json",
    number_format(filesize("$dir/emoji.json") / 1024, 1)
);
