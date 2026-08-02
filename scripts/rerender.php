<?php
declare(strict_types=1);

/**
 * Re-render every page's current revision.
 *
 * Needed whenever Core\Markdown or Core\Highlight changes what HTML they
 * produce: the rendered output is cached in page_revisions.content_html at save
 * time, so existing pages keep the OLD markup until something rewrites them.
 * Symptom of forgetting: a new feature works on pages you edit and nowhere else.
 *
 * Rewrites the current revision in place rather than appending new ones — this
 * is not an edit, and it should not appear in anybody's history or change who
 * the page says it was last updated by.
 *
 * Usage:  php scripts/rerender.php
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line.\n");
}

require dirname(__DIR__) . '/config/config.php';

use Core\DB;
use Core\I18n;
use Core\Markdown;

$spaces = DB::proc('sp_spaces_visible', [0, 1]);
$total = 0;

foreach ($spaces as $space) {
    echo $space['title'] . "\n";

    foreach (DB::proc('sp_page_tree', [(int)$space['id'], 1, I18n::defaultLang()]) as $row) {
      // every language of every page, not just the default one
      foreach (I18n::enabled() as $lang) {
        $page = DB::procOne('sp_page_by_id', [(int)$row['id'], $lang]);
        if (!$page || $page['current_revision_id'] === null) continue;

        $md = (string)($page['content_md'] ?? '');
        $r  = Markdown::render($md);

        DB::exec('sp_revision_rerender', [
            (int)$page['current_revision_id'], $r['html'], $r['text'],
        ]);

        echo '  ' . $row['path'] . ' [' . $lang . "]\n";
        $total++;
      }
    }
}

echo "\nRe-rendered $total page(s).\n";
