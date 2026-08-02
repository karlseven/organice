<?php
declare(strict_types=1);

namespace Core;

/**
 * Whether this installation is one book or many, and what page URLs look like
 * as a result.
 *
 *   multi   — the default. Several spaces, listed on the home page, pages at
 *             /s/<space>/<path>.
 *   single  — one space IS the site. The space list disappears and pages live
 *             at /<path>, which is what a single-product documentation site
 *             actually wants its URLs to look like.
 *
 * Every URL in the application is built through pageUrl() / spaceUrl() so the
 * two shapes exist in ONE place. Scattering `'/s/' . $slug` through the views
 * is what makes a change like this impossible later.
 */
final class Site
{
    public static function mode(): string
    {
        return Settings::get('site_mode', 'multi') === 'single' ? 'single' : 'multi';
    }

    public static function isSingle(): bool
    {
        return self::mode() === 'single' && self::singleSlug() !== '';
    }

    /** The slug of the space that IS the site, or '' when not in single mode. */
    public static function singleSlug(): string
    {
        return Settings::get('single_space', '');
    }

    /**
     * Path segments the application owns. In single mode a ROOT page slug
     * cannot be one of these, or the page would shadow a real route — a page
     * called "Search" would take over /search and the site would lose its
     * search box.
     *
     * @return array<int,string>
     */
    public static function reservedSlugs(): array
    {
        return [
            'admin', 'api', 'brand', 'edit', 'file', 'login', 'logout',
            'robots.txt', 'search', 'sitemap.xml', 's', 'assets',
            // every language prefix, or /th would be swallowed by a page
            ...I18n::enabled(),
        ];
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::reservedSlugs(), true);
    }

    /**
     * URL of a page, in the current language and the current mode.
     *
     * @param string $spaceSlug the space the page belongs to
     * @param string $path      the page's materialized path
     */
    public static function pageUrl(string $spaceSlug, string $path, ?string $lang = null): string
    {
        $prefix = I18n::prefix($lang);

        /* Only the site's OWN space loses its /s/ segment. A single-mode site
           can still have other spaces (an admin may not have deleted them);
           those keep the long form rather than colliding at the root. */
        if (self::isSingle() && $spaceSlug === self::singleSlug()) {
            return url($prefix . '/' . ltrim($path, '/'));
        }
        return url($prefix . '/s/' . $spaceSlug . '/' . ltrim($path, '/'));
    }

    /** URL of a space's landing page. */
    public static function spaceUrl(string $spaceSlug, ?string $lang = null): string
    {
        if (self::isSingle() && $spaceSlug === self::singleSlug()) {
            return url(I18n::prefix($lang) . '/');
        }
        return url(I18n::prefix($lang) . '/s/' . $spaceSlug);
    }

    /**
     * The path used for language switching and canonical links — everything
     * after the language prefix, with no leading slash.
     */
    public static function pagePathForSwitch(string $spaceSlug, string $path): string
    {
        return self::isSingle() && $spaceSlug === self::singleSlug()
            ? ltrim($path, '/')
            : 's/' . $spaceSlug . '/' . ltrim($path, '/');
    }
}
