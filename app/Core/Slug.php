<?php
declare(strict_types=1);

namespace Core;

final class Slug
{
    /**
     * URL segment from arbitrary text. ASCII-folded where a transliteration
     * exists, otherwise the non-ASCII run is dropped — a slug is a URL, and a
     * percent-encoded one is unreadable in a sidebar and unpasteable in chat.
     * A title that folds away to nothing falls back to the caller's default.
     */
    public static function make(string $text, string $fallback = 'page'): string
    {
        $s = $text;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) $s = $t;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = preg_replace('/-{2,}/', '-', $s) ?? '';
        $s = substr($s, 0, 120);
        return $s === '' ? $fallback : $s;
    }

    /**
     * Sanitise a page icon.
     *
     * An icon is one visible character, but "one character" is not one
     * codepoint: a family emoji is seven joined by zero-width joiners, a flag
     * is two regional indicators, and a skin tone adds a modifier. So this
     * caps by codepoint generously rather than trying to count graphemes, and
     * strips the things that would actually cause trouble — newlines, control
     * characters, and the bidi overrides that can reorder surrounding text.
     */
    public static function icon(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') return '';

        // control characters, and the bidi format characters used for spoofing
        $s = preg_replace('/[\x00-\x1F\x7F]|[\x{202A}-\x{202E}]|[\x{2066}-\x{2069}]/u', '', $s) ?? '';

        /* The vector form: a name from the bundled Lucide set.
           Checked against the set rather than merely pattern-matched, so a
           typo or a name retired upstream is refused HERE — while the author is
           looking at the picker and can choose again — instead of being stored
           and silently rendering as nothing on every page for ever after. */
        if (str_starts_with($s, Icon::PREFIX)) {
            $name = Icon::nameOf($s);
            return ($name !== null && Icon::exists($name)) ? Icon::PREFIX . $name : '';
        }

        /* 12 codepoints, and the column is VARCHAR(24) — CHARACTERS, not bytes.
           Measuring the limit in bytes was wrong and truncated a perfectly
           valid ZWJ family (25 bytes, 11 codepoints) down to a broken one. */
        $cut = mb_strlen($s) > 12;
        $s   = mb_substr($s, 0, 12);

        /* A cut can land between an emoji and the joiner or variation selector
           that binds it to the next one, leaving a trailing combining mark that
           renders as a stray box. Trim those off the end — but ONLY when we
           actually cut. Trimming unconditionally quietly mangled every emoji
           that legitimately ENDS in a variation selector: ❤️ is U+2764 followed
           by VS16, and it is the VS16 that asks for the red emoji rather than
           the monochrome ❤ dingbat. */
        if ($cut) {
            $s = preg_replace('/[\x{200D}\x{FE0F}\x{FE0E}]+$/u', '', $s) ?? $s;
        }

        /* An icon is a SYMBOL, not a short string. Everything downstream escapes
           it, so accepting "<img src=x>" here was never an injection — it simply
           rendered as that text beside the page name. Rejecting it at the door
           is still right: relying on the escaper means the field is only ever one
           missed context (an attribute, a JSON blob, a PDF export) away from
           mattering, and a title-shaped icon is wrong even when it is safe.

           Allowed: symbols (\p{S} — covers emoji, dingbats, arrows, regional
           indicators for flags), combining marks (\p{M} — the enclosing keycap
           that turns "1" into 1️⃣), the joiners above, and the skin-tone
           modifiers. Anything containing a letter or ASCII markup is refused
           outright rather than filtered, because a half-stripped icon is a
           silent corruption of what the author typed. */
        if ($s !== '' && !preg_match(
            '/^(?:[\p{S}\p{M}\x{200D}\x{FE0F}\x{FE0E}\x{1F3FB}-\x{1F3FF}]|[0-9#*](?=\x{FE0F}?\x{20E3}))+$/u',
            $s
        )) {
            return '';
        }

        return $s;
    }

    /**
     * Heading anchor, kept unique within one page. $seen is by reference so the
     * caller keeps one counter for the whole document — two headings called
     * "Options" must not both be #options or the second link goes to the first.
     */
    public static function anchor(string $text, array &$seen): string
    {
        $base = self::make($text, 'section');
        $slug = $base;
        $n = 2;
        while (isset($seen[$slug])) {
            $slug = $base . '-' . $n++;
        }
        $seen[$slug] = true;
        return $slug;
    }

    /**
     * A slug not already used by one of $siblings (an array of rows with a
     * 'slug' key). Called before any rename, because sp_page_paths_rebuild
     * enforces uniqueness per statement and would fail mid-rebuild otherwise.
     */
    public static function unique(string $wanted, array $siblings, int $ignoreId = 0): string
    {
        $taken = [];
        foreach ($siblings as $s) {
            if ((int)$s['id'] === $ignoreId) continue;
            $taken[$s['slug']] = true;
        }
        $slug = $wanted;
        $n = 2;
        while (isset($taken[$slug])) {
            $slug = $wanted . '-' . $n++;
        }
        return $slug;
    }
}
