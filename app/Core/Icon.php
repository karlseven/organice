<?php
declare(strict_types=1);

namespace Core;

/**
 * The bundled Lucide icon set, and the two kinds of thing a page icon can be.
 *
 * A page icon is stored in `pages.icon` as EITHER:
 *
 *   lucide:rocket   a named vector icon from the bundled set
 *   🚀              a literal Unicode character (emoji, arrow, dingbat)
 *
 * Both are supported on purpose and neither is going away. They are genuinely
 * different things and authors want both:
 *
 *   A vector icon is monochrome and inherits `color`, so it turns blue when its
 *   page is the active one and grey when it is not, and a whole sidebar of them
 *   looks like one designed set. That is the look this borrows from GitBook.
 *
 *   An emoji is full colour and fixed. It cannot follow the accent, and mixing
 *   ⚖ (a thin text dingbat) with 🎁 (a colour emoji) never looks deliberate.
 *   But it needs no asset, renders anywhere, and sometimes a red 🔥 is exactly
 *   what the author meant.
 *
 * The stored prefix, not a guess about the character, decides which is which.
 *
 * Icons are INLINED into the page rather than referenced from the sprite. See
 * the note in scripts/build-icons.php for why: a reader would otherwise fetch
 * ~650 KB to use a handful of icons, on HTML that is sent `no-store`.
 */
final class Icon
{
    public const PREFIX = 'lucide:';

    /** Matches the existing UI stroke weight — see scripts/build-icons.php. */
    private const STROKE = '1.8';

    /** @var array<string,string>|null name => inner SVG markup */
    private static ?array $set = null;

    /**
     * Short names used by the chrome (buttons, nav) mapped onto Lucide names.
     *
     * This table used to BE the icon set — two dozen paths pasted into
     * app/helpers.php by hand. Keeping the short names as aliases means the
     * views that call icon('trash') did not have to change, and the app now has
     * one icon vocabulary instead of two that drift apart.
     */
    private const ALIAS = [
        'book' => 'book', 'file' => 'file', 'caret' => 'chevron-down',
        'caretR' => 'chevron-right', 'search' => 'search', 'edit' => 'square-pen',
        'plus' => 'plus', 'trash' => 'trash-2', 'history' => 'history',
        'lock' => 'lock', 'globe' => 'globe', 'user' => 'user',
        'logout' => 'log-out', 'settings' => 'settings', 'sun' => 'sun',
        'moon' => 'moon', 'menu' => 'menu', 'close' => 'x', 'image' => 'image',
        'link' => 'link', 'eye' => 'eye', 'check' => 'check', 'info' => 'info',
    ];

    /** @return array<string,string> */
    private static function set(): array
    {
        /* Loaded on first use, not at boot: ~400 KB and about 1 ms to build, so
           a request that draws no icons should not pay for it. */
        return self::$set ??= require BASE_PATH . '/app/Data/lucide-icons.php';
    }

    public static function exists(string $name): bool
    {
        return isset(self::set()[$name]);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::set());
    }

    /** The Lucide name inside a stored value, or null if it is not one. */
    public static function nameOf(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) return null;
        $n = substr($stored, strlen(self::PREFIX));
        return preg_match('/^[a-z0-9-]+$/', $n) === 1 ? $n : null;
    }

    /**
     * Inline <svg> for a Lucide name, or '' when the name is unknown.
     *
     * An unknown name renders as nothing rather than as a placeholder box. The
     * name may be unknown because the set was regenerated and an icon was
     * renamed upstream, and a page quietly losing its decoration is a much
     * better failure than every such page showing a broken-image glyph.
     */
    public static function svg(string $name, int $size = 18, string $class = 'ic'): string
    {
        $inner = self::set()[$name] ?? null;
        if ($inner === null) return '';

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
            . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="' . self::STROKE . '"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $inner . '</svg>';
    }

    /** Resolve a chrome alias (or a plain Lucide name) and render it. */
    public static function chrome(string $name, int $size = 18): string
    {
        return self::svg(self::ALIAS[$name] ?? $name, $size);
    }

    /**
     * Render whatever is stored in pages.icon, ready to print.
     *
     * Returns '' for an empty icon so callers can test the result directly
     * rather than repeating the "is there an icon" check.
     *
     * The literal-character branch is ESCAPED. It is author input, and although
     * Slug::icon already refuses anything but symbols, output escaping is not
     * something to make conditional on an input filter being correct.
     */
    public static function page(string $stored, int $size = 18): string
    {
        if ($stored === '') return '';

        $name = self::nameOf($stored);
        if ($name !== null) return self::svg($name, $size, 'ic page-ic');

        return htmlspecialchars($stored, ENT_QUOTES, 'UTF-8');
    }

    /** URL of the sprite the picker uses. Authors only — never on a read path. */
    public static function spriteUrl(): string
    {
        return APP_BASE . '/assets/icons/lucide.svg';
    }
}
