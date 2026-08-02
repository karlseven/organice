<?php
declare(strict_types=1);

/**
 * PHP 8 string helpers, for PHP 7.4.
 *
 * The app supports 7.4 upward, and these three are the only 8.x functions it
 * uses. Each is a two-line job, which is far less trouble than avoiding them
 * across sixty files. Guarded on function_exists, so PHP 8 uses its native
 * versions and pays nothing.
 *
 * Its own file rather than living inside config.php because the standalone
 * scripts need it too. `scripts/security-check.php` in particular does not — and
 * must not — bootstrap the application, since its job is to inspect a
 * deployment that may be broken; but it is full of str_contains(), so on 7.4 it
 * died before printing a single check.
 *
 * The empty-needle cases are not pedantry. PHP 8's str_contains('abc', '') is
 * TRUE — every string contains the empty string — while strpos() returns 0,
 * which is falsy. A naive polyfill inverts the answer for that input.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
