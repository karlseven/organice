<?php
declare(strict_types=1);

namespace Core;

final class Request
{
    /** Trimmed GET value. */
    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** Trimmed POST value. */
    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        return (int)($_POST[$key] ?? $default);
    }

    /**
     * Decoded JSON body, for the editor's fetch() calls. Cached: php://input
     * can only be read once under some SAPIs.
     */
    public static function json(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return $cached = is_array($data) ? $data : [];
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }
}
