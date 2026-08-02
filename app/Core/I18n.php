<?php
declare(strict_types=1);

namespace Core;

/**
 * Locale selection and UI string lookup.
 *
 * The default language is served WITHOUT a URL prefix and every other language
 * with one (`/th/s/handbook/writing`). That choice keeps every URL that existed
 * before this feature working unchanged, and means a site that never enables a
 * second language never grows a locale segment at all.
 *
 * Content translation is separate and lives in `page_locales`; this class only
 * decides which language is being *requested* and translates the chrome.
 */
final class I18n
{
    /**
     * Every language the software knows how to render, with the endonym — the
     * name of the language IN that language. A switcher that lists "Japanese"
     * in English is useless to the reader who needs it.
     *
     * `ngram` marks the languages that do not put spaces between words and so
     * need MySQL's ngram FULLTEXT parser. That list is duplicated in
     * sp_revision_create and sp_search; the three must agree.
     */
    public const LANGUAGES = [
        'en' => ['name' => 'English',   'endonym' => 'English',    'ngram' => false],
        'th' => ['name' => 'Thai',      'endonym' => 'ไทย',         'ngram' => true],
        'ko' => ['name' => 'Korean',    'endonym' => '한국어',       'ngram' => false],
        'ja' => ['name' => 'Japanese',  'endonym' => '日本語',       'ngram' => true],
        'vi' => ['name' => 'Vietnamese','endonym' => 'Tiếng Việt',  'ngram' => false],
        'id' => ['name' => 'Indonesian','endonym' => 'Bahasa Indonesia', 'ngram' => false],
        'zh' => ['name' => 'Chinese',   'endonym' => '中文',         'ngram' => true],
    ];

    private static string $lang = 'en';
    private static string $default = 'en';
    /** @var array<string,string>|null */
    private static ?array $strings = null;
    /** @var array<string,string>|null */
    private static ?array $fallbackStrings = null;

    // -----------------------------------------------------------------------
    // configuration
    // -----------------------------------------------------------------------

    public static function defaultLang(): string
    {
        static $d = null;
        if ($d === null) {
            $d = Settings::get('default_lang', 'en');
            if (!isset(self::LANGUAGES[$d])) $d = 'en';
        }
        return self::$default = $d;
    }

    /**
     * The languages this site has switched on, default first.
     *
     * @return array<int,string>
     */
    public static function enabled(): array
    {
        static $list = null;
        if ($list !== null) return $list;

        $raw = Settings::get('languages', 'en');
        $out = [];
        foreach (explode(',', $raw) as $code) {
            $code = trim($code);
            if (isset(self::LANGUAGES[$code]) && !in_array($code, $out, true)) $out[] = $code;
        }

        // the default language is always available, whatever the setting says
        $d = self::defaultLang();
        if (!in_array($d, $out, true)) array_unshift($out, $d);

        return $list = $out;
    }

    public static function isEnabled(string $code): bool
    {
        return in_array($code, self::enabled(), true);
    }

    public static function usesNgram(string $code): bool
    {
        return self::LANGUAGES[$code]['ngram'] ?? false;
    }

    public static function endonym(string $code): string
    {
        return self::LANGUAGES[$code]['endonym'] ?? $code;
    }

    // -----------------------------------------------------------------------
    // selection
    // -----------------------------------------------------------------------

    public static function current(): string
    {
        return self::$lang;
    }

    public static function isDefault(): bool
    {
        return self::$lang === self::defaultLang();
    }

    public static function set(string $code): void
    {
        self::$lang = self::isEnabled($code) ? $code : self::defaultLang();
        self::$strings = null;
    }

    /**
     * Pick a language when the URL did not name one.
     *
     * Order: the reader's own saved choice, then what their browser asks for,
     * then the site default. The saved choice wins over Accept-Language because
     * someone who has explicitly chosen a language has told us something more
     * reliable than their browser's configuration.
     */
    public static function negotiate(): string
    {
        $cookie = $_COOKIE['organice_lang'] ?? '';
        if (is_string($cookie) && self::isEnabled($cookie)) return $cookie;

        foreach (self::parseAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $tag) {
            // "zh-Hant-TW" and "zh-CN" both reduce to the "zh" we support
            $base = strtolower(explode('-', $tag)[0]);
            if (self::isEnabled($base)) return $base;
        }

        return self::defaultLang();
    }

    /**
     * Accept-Language tags, highest quality first.
     *
     * @return array<int,string>
     */
    private static function parseAcceptLanguage(string $header): array
    {
        $out = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag  = trim($bits[0]);
            if ($tag === '' || $tag === '*') continue;
            $out[$tag] = isset($bits[1]) ? (float)$bits[1] : 1.0;
        }
        arsort($out);
        return array_keys($out);
    }

    /** Remember the reader's choice for a year. */
    public static function remember(string $code): void
    {
        if (!self::isEnabled($code)) return;
        setcookie('organice_lang', $code, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'httponly' => false,   // read by no script today, but harmless and useful later
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }

    // -----------------------------------------------------------------------
    // strings
    // -----------------------------------------------------------------------

    /**
     * A UI string. `$vars` are substituted as :name.
     *
     * A missing key falls back to the default language's string, and then to
     * the key itself. A half-translated catalogue therefore shows English in
     * the gaps rather than blank space or an error — the only behaviour that
     * degrades usefully.
     *
     * @param array<string,string|int> $vars
     */
    public static function t(string $key, array $vars = []): string
    {
        if (self::$strings === null) {
            self::$strings = self::load(self::$lang);
        }
        if (self::$fallbackStrings === null) {
            self::$fallbackStrings = self::load(self::defaultLang());
        }

        $s = self::$strings[$key] ?? self::$fallbackStrings[$key] ?? $key;

        foreach ($vars as $k => $v) {
            $s = str_replace(':' . $k, (string)$v, $s);
        }
        return $s;
    }

    /** @return array<string,string> */
    private static function load(string $code): array
    {
        $file = APP_PATH . '/Lang/' . $code . '.php';
        if (!is_file($file)) return [];
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    // -----------------------------------------------------------------------
    // URLs
    // -----------------------------------------------------------------------

    /**
     * The prefix for a language: '' for the default, '/th' for the rest.
     * Kept in one place because getting it wrong produces URLs that work but
     * silently drop the reader back to English.
     */
    public static function prefix(?string $code = null): string
    {
        $code ??= self::$lang;
        return $code === self::defaultLang() ? '' : '/' . $code;
    }

    /** The current request's path with a different language's prefix. */
    public static function swapUrl(string $path, string $toLang): string
    {
        return url(self::prefix($toLang) . '/' . ltrim($path, '/'));
    }
}
