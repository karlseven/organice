<?php
declare(strict_types=1);

namespace Core;

/**
 * Records the things that page_revisions cannot: sign-ins, deletions, and
 * changes to who can do what.
 *
 * Page edits are deliberately NOT logged here — every one of them is already a
 * row in page_revisions with an author and a timestamp, and duplicating them
 * would bury the security-relevant entries under ordinary writing.
 */
final class Audit
{
    public static function log(
        string $action,
        string $targetType = '',
        int $targetId = 0,
        string $detail = ''
    ): void {
        $user = Auth::user();

        /* The actor's name is stored alongside the id. The foreign key is
           ON DELETE SET NULL so the record survives the account being deleted,
           and without the denormalised name the surviving row would say only
           that "someone" deleted a space. */
        try {
            DB::exec('sp_audit_add', [
                (int)($user['id'] ?? 0),
                (string)($user['display_name'] ?? 'anonymous'),
                $action,
                $targetType,
                $targetId,
                mb_substr($detail, 0, 500),
                Ip::packed(),
            ]);
        } catch (\Throwable $e) {
            /* An audit write must never be what breaks the action being
               audited — a failed delete that the admin thinks succeeded is
               worse than a missing log line. It is still recorded somewhere. */
            error_log('audit failed: ' . $e->getMessage() . ' (' . $action . ')');
        }
    }
}
