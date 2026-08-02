<?php
declare(strict_types=1);

/**
 * Sweep unreferenced uploads.
 *
 * Two different kinds of garbage accumulate, and they are not the same problem:
 *
 *   ORPHANED FILES   bytes on disk with no `assets` row. Produced when a space
 *                    is deleted: the rows cascade away, the files do not.
 *                    Nothing can reach them, so they are safe to remove.
 *
 *   UNREFERENCED     an `assets` row whose sha appears in no revision of any
 *   ASSETS           page. Produced when someone uploads an image and then
 *                    deletes the line that used it.
 *
 * The second kind is where care is needed. "Referenced" means referenced by ANY
 * revision, not just current ones — an old revision is still readable in the
 * history panel and restorable, and deleting its images silently breaks it.
 * A grace period on top of that covers the upload that has been pasted into an
 * editor but not yet saved.
 *
 * DRY RUN BY DEFAULT. Deleting uploaded files is not undoable, so it takes an
 * explicit flag.
 *
 * Usage:
 *   php scripts/gc-assets.php              report only
 *   php scripts/gc-assets.php --delete     actually remove
 *   php scripts/gc-assets.php --delete --grace-days=30
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line.\n");
}

require dirname(__DIR__) . '/config/config.php';

use Core\DB;
use Core\Uploader;

$argvFlags = array_slice($argv, 1);
$doDelete  = in_array('--delete', $argvFlags, true);
$grace     = 7;
foreach ($argvFlags as $a) {
    if (preg_match('/^--grace-days=(\d+)$/', $a, $m)) $grace = (int)$m[1];
}

echo $doDelete ? "Sweeping (DELETING).\n" : "Dry run — nothing will be removed. Pass --delete to act.\n";
echo "Grace period: $grace days\n\n";

// ---------------------------------------------------------------------------
// 1. every sha referenced by any revision, in any language
// ---------------------------------------------------------------------------
$referenced = [];
$after = 0;
$scanned = 0;

while (true) {
    $rows = DB::proc('sp_revision_bodies', [$after, 500]);
    if ($rows === []) break;

    foreach ($rows as $r) {
        $after = (int)$r['id'];
        $scanned++;
        /* Matches the URL AssetController serves. Deliberately loose about what
           follows the hash — a reference may be an image, a link, or sit inside
           an HTML attribute someone pasted. */
        if (preg_match_all('#/file/([a-f0-9]{64})/#', (string)$r['content_md'], $m)) {
            foreach ($m[1] as $sha) $referenced[$sha] = true;
        }
    }
}

echo "Scanned $scanned revision(s); " . count($referenced) . " referenced file(s).\n";

// ---------------------------------------------------------------------------
// 2. assets rows nothing points at any more
// ---------------------------------------------------------------------------
$assets = DB::proc('sp_assets_all');
$known  = [];
$deadRows = [];
$freed = 0;

foreach ($assets as $a) {
    $known[$a['sha256']] = true;

    if (isset($referenced[$a['sha256']])) continue;
    if ((int)$a['age_days'] < $grace) continue;   // may be in an unsaved editor

    $deadRows[] = $a;
    $freed += (int)$a['size_bytes'];
}

echo "\nUnreferenced assets: " . count($deadRows) . ' (' . fmt($freed) . ")\n";
foreach ($deadRows as $a) {
    echo '  ' . substr($a['sha256'], 0, 12) . '  ' . str_pad($a['filename'], 34)
       . ' ' . fmt((int)$a['size_bytes']) . '  ' . $a['age_days'] . "d old\n";
}

// ---------------------------------------------------------------------------
// 3. files on disk with no row at all
// ---------------------------------------------------------------------------
$orphans = [];
$orphanBytes = 0;

if (is_dir(UPLOAD_PATH)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $name = $file->getFilename();
        if (!preg_match('/^[a-f0-9]{64}$/', $name)) continue;   // not one of ours
        if (isset($known[$name])) continue;

        $orphans[] = $file->getPathname();
        $orphanBytes += $file->getSize();
    }
}

echo "\nOrphaned files (no database row): " . count($orphans) . ' (' . fmt($orphanBytes) . ")\n";

// ---------------------------------------------------------------------------
// 4. act
// ---------------------------------------------------------------------------
if (!$doDelete) {
    echo "\nTotal recoverable: " . fmt($freed + $orphanBytes) . "\n";
    echo "Re-run with --delete to remove.\n";
    exit(0);
}

$removed = 0;

foreach ($deadRows as $a) {
    /* The row goes first. A blob shared by two spaces has two rows and one
       file, so the file is only unlinked once nothing else claims that sha. */
    DB::exec('sp_asset_delete', [(int)$a['id']]);

    $stillClaimed = false;
    foreach (DB::proc('sp_assets_all') as $other) {
        if ($other['sha256'] === $a['sha256']) { $stillClaimed = true; break; }
    }
    if ($stillClaimed) continue;

    $path = Uploader::path($a['sha256']);
    if (is_file($path) && unlink($path)) $removed++;
}

foreach ($orphans as $path) {
    if (unlink($path)) $removed++;
}

echo "\nRemoved $removed file(s), " . fmt($freed + $orphanBytes) . " freed.\n";

/* Empty fan-out directories are swept last. They cost an inode each and
   accumulate forever otherwise; rmdir refuses to touch a non-empty one, so
   this cannot delete anything that still matters. */
if (is_dir(UPLOAD_PATH)) {
    foreach (glob(UPLOAD_PATH . '/*/*') ?: [] as $dir) if (is_dir($dir)) @rmdir($dir);
    foreach (glob(UPLOAD_PATH . '/*') ?: [] as $dir) if (is_dir($dir)) @rmdir($dir);
}

function fmt(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
