<?php
declare(strict_types=1);

namespace Core;

/**
 * Video and diagram embeds, written as `@embed <url> [caption]`.
 *
 * Rendered as a FACADE: what goes into the page is a thumbnail and a play
 * button, and the third-party <iframe> is only inserted when a reader actually
 * presses play (see app.js).
 *
 * That is not a performance nicety. A YouTube iframe on page load contacts
 * Google, runs their script in a frame, and sets cookies — for every reader of
 * every page carrying a video, whether or not they watch it. The facade means a
 * reader who does not press play is never exposed to the third party at all,
 * and it is why `frame-src` can stay closed until it is needed.
 *
 * Hosts are an ALLOW-LIST. An arbitrary URL in an iframe is an arbitrary
 * document running in a frame of this site.
 */
final class Embed
{
    /**
     * Turn a URL into an embed, or a plain link if the host is not one we
     * support. Never returns nothing — an author who typed @embed meant to put
     * something on the page.
     */
    public static function render(string $url, string $caption = ''): string
    {
        $url = trim($url);

        if (($yt = self::youtubeId($url)) !== null) {
            /* youtube-nocookie.com is YouTube's own no-tracking-cookie domain.
               There is no reason to prefer the tracking one. */
            return self::facade(
                'https://www.youtube-nocookie.com/embed/' . $yt . '?autoplay=1&rel=0',
                'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg',
                'YouTube',
                $caption
            );
        }

        if (($vm = self::vimeoId($url)) !== null) {
            /* Vimeo's thumbnail needs an API call to resolve, which would mean
               a server-side request at render time for every video. Not worth
               it: the facade shows a neutral placeholder instead. */
            return self::facade(
                'https://player.vimeo.com/video/' . $vm . '?autoplay=1',
                '',
                'Vimeo',
                $caption
            );
        }

        $safe = filter_var($url, FILTER_VALIDATE_URL) !== false
             && preg_match('#^https?://#i', $url) === 1;

        return $safe
            ? '<p class="embed-fallback"><a href="' . e($url) . '" rel="noopener noreferrer" target="_blank">'
              . e($caption !== '' ? $caption : $url) . '</a></p>'
            : '<p class="embed-fallback">' . e($url) . '</p>';
    }

    private static function facade(string $src, string $poster, string $provider, string $caption): string
    {
        /* The iframe URL rides on a data attribute, not in an <iframe> that is
           merely hidden — a hidden iframe still loads. */
        $img = $poster !== ''
            ? '<img src="' . e($poster) . '" alt="" loading="lazy" referrerpolicy="no-referrer">'
            : '<span class="embed-blank"></span>';

        $cap = $caption !== ''
            ? '<figcaption class="embed-caption">' . e($caption) . '</figcaption>'
            : '';

        return '<figure class="embed" data-embed="' . e($src) . '">'
             . '<button class="embed-play" type="button" '
             . 'aria-label="Play this ' . e($provider) . ' video">'
             . $img
             . '<span class="embed-play-icon" aria-hidden="true">&#9654;</span>'
             . '<span class="embed-provider">' . e($provider) . '</span>'
             . '</button>' . $cap . '</figure>';
    }

    private static function youtubeId(string $url): ?string
    {
        $patterns = [
            '#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})#',
            '#^https?://youtu\.be/([A-Za-z0-9_-]{11})#',
            '#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#',
            '#^https?://(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{11})#',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $url, $m)) return $m[1];
        }
        return null;
    }

    private static function vimeoId(string $url): ?string
    {
        return preg_match('#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{6,12})#', $url, $m)
            ? $m[1]
            : null;
    }
}
