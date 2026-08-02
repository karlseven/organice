<?php
declare(strict_types=1);

namespace Core;

/**
 * One token per session, checked on every POST by the front controller.
 *
 * The token is read from the form field OR the X-CSRF-Token header, because
 * the editor saves over fetch() and cannot post a form body.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['__csrf'])) {
            $_SESSION['__csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['__csrf'];
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            throw new HttpError(419, 'Your session expired. Reload the page and try again.');
        }
    }
}
