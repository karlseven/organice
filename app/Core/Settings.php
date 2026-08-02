<?php
declare(strict_types=1);

namespace Core;

/** Site-wide key/value, loaded once per request. */
final class Settings
{
    private static ?array $all = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (DB::proc('sp_setting_all') as $row) {
                self::$all[$row['k']] = $row['v'];
            }
        }
        return self::$all[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        DB::exec('sp_setting_set', [$key, $value]);
        self::$all[$key] = $value;
    }
}
