<?php
declare(strict_types=1);

namespace Core;

/**
 * Machine translation of page Markdown.
 *
 * Disabled unless a driver is configured. Two are supported:
 *
 *   google         Google Cloud Translation API v2. Needs an API key and a
 *                  billing account; charged per character. Note this is the
 *                  paid Cloud API — the free "Website Translator" widget that
 *                  used to be dropped into a page was discontinued, and no
 *                  free replacement exists.
 *   libretranslate A self-hosted LibreTranslate instance. No per-character
 *                  cost and nothing leaves your network, at the price of
 *                  running it and of noticeably weaker output for the Asian
 *                  languages here.
 *
 * Translation is always EXPLICIT — an editor presses a button for one page and
 * one language. Nothing is translated on save. Two reasons: cost is per
 * character and an automatic pass over a whole site is expensive in a way that
 * is invisible until the bill, and content leaving the server is a decision
 * somebody should make deliberately rather than discover.
 *
 * The result is stored as a normal revision marked `source = 'machine'`, shown
 * to readers with a badge, and left as a draft for review.
 */
final class Translator
{
    /** Longest single request. Both APIs cap the payload; this stays well under. */
    private const CHUNK = 4500;

    public static function driver(): string
    {
        return Settings::get('mt_driver', '');
    }

    public static function available(): bool
    {
        return in_array(self::driver(), ['google', 'libretranslate'], true)
            && self::key() !== '';
    }

    private static function key(): string
    {
        // credentials live in .env, never in the settings table — the settings
        // table is editable from a web form and readable by every admin
        return (string)($GLOBALS['__env']['MT_KEY'] ?? getenv('MT_KEY') ?: '');
    }

    private static function endpoint(): string
    {
        $url = (string)($GLOBALS['__env']['MT_URL'] ?? getenv('MT_URL') ?: '');
        if ($url !== '') return rtrim($url, '/');
        return self::driver() === 'google'
            ? 'https://translation.googleapis.com/language/translate/v2'
            : 'http://127.0.0.1:5000/translate';
    }

    /**
     * Translate Markdown, preserving everything that must not be translated.
     *
     * @throws HttpError when the driver is off or the API refuses
     */
    public static function markdown(string $md, string $from, string $to): string
    {
        if (!self::available()) {
            throw new HttpError(503, 'Machine translation is not configured.');
        }

        [$masked, $vault] = self::mask($md);

        $out = '';
        foreach (self::chunks($masked) as $chunk) {
            $out .= self::call($chunk, $from, $to);
        }

        return self::unmask($out, $vault);
    }

    /**
     * Replace everything that must survive translation verbatim with opaque
     * tokens.
     *
     * Code, URLs and image references are not prose and a translator will
     * happily mangle them — translating identifiers inside a code block, or
     * "helpfully" localising a path. The token is deliberately bracketed ASCII
     * digits: short enough not to disturb the sentence it sits in, and made of
     * characters no translation engine tries to inflect.
     *
     * @return array{0:string, 1:array<int,string>}
     */
    private static function mask(string $md): array
    {
        $vault = [];
        $keep = static function (string $text) use (&$vault): string {
            $vault[] = $text;
            return '⟦' . (count($vault) - 1) . '⟧';
        };

        // order matters exactly as in Core\Markdown: fenced code before inline
        $out = preg_replace_callback(
            '/^\s*(`{3,}|~{3,})[\s\S]*?^\s*\1[^\n]*$/m',
            static fn(array $m): string => $keep($m[0]),
            $md
        ) ?? $md;

        $out = preg_replace_callback('/`[^`\n]+`/', static fn(array $m): string => $keep($m[0]), $out) ?? $out;

        /* Only the URL half of a link is masked — the link TEXT is prose and
           should be translated, or every link on the page stays in English. */
        $out = preg_replace_callback(
            '/(!?\[[^\]]*\])\(([^)\s]+)([^)]*)\)/',
            static fn(array $m): string => $m[1] . '(' . $keep($m[2]) . $m[3] . ')',
            $out
        ) ?? $out;

        $out = preg_replace_callback('#(?<![(\w])https?://\S+#', static fn(array $m): string => $keep($m[0]), $out) ?? $out;

        // container fences and tab labels are syntax, not sentences
        $out = preg_replace_callback('/^:::.*$/m', static fn(array $m): string => $keep($m[0]), $out) ?? $out;

        return [$out, $vault];
    }

    /** @param array<int,string> $vault */
    private static function unmask(string $text, array $vault): string
    {
        /* Tolerant of whitespace the engine may have introduced inside the
           token — "⟦ 3 ⟧" still resolves. A token it mangled beyond that is
           left visible on purpose: a visible ⟦3⟧ tells the reviewer exactly
           where to look, whereas silently dropping it would delete a code block
           with nothing to show for it. */
        return preg_replace_callback(
            '/⟦\s*(\d+)\s*⟧/u',
            static fn(array $m): string => $vault[(int)$m[1]] ?? $m[0],
            $text
        ) ?? $text;
    }

    /**
     * Split on blank lines, never mid-paragraph — a sentence cut in half
     * translates badly in every language and catastrophically in the ones
     * without spaces between words.
     *
     * @return array<int,string>
     */
    private static function chunks(string $text): array
    {
        $paras = preg_split('/(\n{2,})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = [];
        $buf = '';

        foreach ($paras as $part) {
            if (strlen($buf) + strlen($part) > self::CHUNK && $buf !== '') {
                $out[] = $buf;
                $buf = '';
            }
            $buf .= $part;
        }
        if ($buf !== '') $out[] = $buf;

        return $out;
    }

    private static function call(string $text, string $from, string $to): string
    {
        if (trim($text) === '') return $text;

        $isGoogle = self::driver() === 'google';

        $payload = $isGoogle
            ? ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text']
            : ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text',
               'api_key' => self::key()];

        $url = self::endpoint() . ($isGoogle ? '?key=' . rawurlencode(self::key()) : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            // certificate verification stays ON; a translation is not worth a
            // silent downgrade to an unauthenticated channel
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new HttpError(502, 'Translation service unreachable: ' . $err);
        }
        if ($status !== 200) {
            /* The upstream message is logged but NOT shown: a Google error body
               echoes the API key back in some failure modes. */
            error_log('translate ' . $status . ': ' . substr((string)$body, 0, 500));
            throw new HttpError(502, 'The translation service refused the request (HTTP ' . $status . ').');
        }

        $data = json_decode((string)$body, true);

        $result = $isGoogle
            ? ($data['data']['translations'][0]['translatedText'] ?? null)
            : ($data['translatedText'] ?? null);

        if (!is_string($result)) {
            throw new HttpError(502, 'The translation service returned nothing usable.');
        }

        /* format=text still comes back HTML-escaped from Google. Left as-is it
           puts literal &#39; through the Markdown parser and into the page. */
        return $isGoogle
            ? html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $result;
    }
}
