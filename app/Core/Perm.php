<?php
declare(strict_types=1);

namespace Core;

/**
 * Who may read and who may write a space.
 *
 * Site role and space membership are additive: a site admin edits everything,
 * a site 'editor' can still only edit spaces they belong to. Both questions
 * are answered from the single space row the controller already loaded (it
 * carries member_role), so authorisation costs no extra query.
 */
final class Perm
{
    public static function canRead(array $space): bool
    {
        if (Auth::isAdmin()) return true;
        if ($space['visibility'] === 'public') return true;
        if ($space['visibility'] === 'internal' && Auth::check()) return true;
        return ($space['member_role'] ?? '') !== '';
    }

    public static function canWrite(array $space): bool
    {
        if (Auth::isAdmin()) return true;
        if (!Auth::check()) return false;
        return in_array($space['member_role'] ?? '', ['owner', 'editor'], true);
    }

    /** Read access or a 404 — never a 403, see Core\HttpError. */
    public static function requireRead(array $space): void
    {
        if (!self::canRead($space)) throw new HttpError(404, 'Page not found.');
    }

    public static function requireWrite(array $space): void
    {
        // The space is already known to be readable by the time we get here, so
        // 403 leaks nothing and is the more useful answer.
        if (!self::canWrite($space)) throw new HttpError(403, 'You cannot edit this space.');
    }

    /** Drafts are visible only to people who could edit the space. */
    public static function seesDrafts(array $space): bool
    {
        return self::canWrite($space);
    }
}
