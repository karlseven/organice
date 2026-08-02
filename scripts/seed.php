<?php
declare(strict_types=1);

/**
 * First-run seed: one admin account and a demo space that documents the
 * Markdown this site actually supports.
 *
 * Usage:  php scripts/seed.php [email] [password]
 * Safe to re-run: it stops if any user already exists.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line.\n");
}

require dirname(__DIR__) . '/config/config.php';

use Core\Auth;
use Core\DB;
use Core\I18n;
use Core\Markdown;

$email = $argv[1] ?? 'admin@example.com';
$pass  = $argv[2] ?? bin2hex(random_bytes(8));

if (DB::proc('sp_users_all') !== []) {
    exit("There are already users in this database — nothing to seed.\n");
}

$admin = DB::procOne('sp_user_create', [$email, 'admin', 'Administrator', Auth::hash($pass), 'admin']);
$adminId = (int)($admin['id'] ?? 0);
echo "Created admin: $email\n";
echo "Password:      $pass\n\n";

$space = DB::procOne('sp_space_create', [
    'handbook', 'Handbook', 'How this site works, and what you can write in it.',
    'public', '#5b7cfa', $adminId,
]);
$spaceId = (int)($space['id'] ?? 0);

/** @var array<int,array{slug:string,title:string,parent:string|null,md:string}> */
$pages = [
    [
        'slug' => 'introduction', 'title' => 'Introduction', 'parent' => null,
        'md' => <<<'MD'
Welcome. This space was created by `scripts/seed.php` and exists to show what
the editor can do — delete it once you have your own content.

## How it is organised

Documentation lives in **spaces**. A space is one book: one product, one team,
one handbook. Inside a space, pages form a tree, and that tree is the sidebar
you see on the left.

:::tip Getting started
Sign in, open any page, and press **Edit**. The editor writes Markdown on the
left and shows the rendered page on the right as you type.
:::

## Who can see what

| Visibility | Who can read it |
| --- | --- |
| Public | Anyone, signed in or not |
| Internal | Any signed-in user |
| Private | Only members of that space |
MD,
    ],
    [
        'slug' => 'writing', 'title' => 'Writing pages', 'parent' => null,
        'md' => <<<'MD'
Pages are written in Markdown. The usual things work.

## Text

**Bold**, _italic_, ~~struck through~~, `inline code`, and [links](https://example.com).

## Lists

- Bulleted items
- Nest them by indenting two spaces
  - Like this
- [x] Task lists render as checkboxes
- [ ] Ticked ones too

## Code

Fenced blocks take a language, and an optional title after it:

```php config/config.php
define('DB_NAME', $env['DB_NAME'] ?? 'organice');
```

## Callouts

:::info
For something a reader should know.
:::

:::warning Careful
For something that will bite them.
:::

:::danger Do not do this
For something irreversible.
:::

The five kinds are `info`, `tip`, `note`, `warning` and `danger`.
MD,
    ],
    [
        'slug' => 'images', 'title' => 'Images and files', 'parent' => 'writing',
        'md' => <<<'MD'
Paste or drag an image straight into the editor and it uploads. You get a
Markdown image link back, already pointing at the stored file.

Uploads are kept **outside the webroot** and served through the application,
which checks the owning space's visibility first — a private space's
screenshots stay private even if someone learns the URL.

:::note
Files are stored under their SHA-256, so uploading the same screenshot to ten
pages stores one file.
:::
MD,
    ],
    [
        'slug' => 'organising', 'title' => 'Organising a space', 'parent' => null,
        'md' => <<<'MD'
## Renaming and moving

Both are safe. Every rename or move records the old path as a redirect, so
links people have already shared keep working.

## Drafts

A page is a draft until you publish it. Drafts are visible only to people who
can edit the space, and are never returned by search.

## Search

Search covers the published pages of every space you are allowed to read.
Words shorter than three characters are ignored, and code blocks are excluded
from the index — a search for `user` finds the page about users, not every
page with a `user` variable in a snippet.
MD,
    ],
];

$lang = I18n::defaultLang();
$ids = [];
foreach ($pages as $p) {
    $parent = $p['parent'] !== null ? ($ids[$p['parent']] ?? 0) : 0;
    $row = DB::procOne('sp_page_create', [$spaceId, $parent, $p['slug'], $p['title'], $adminId, $lang]);
    $id  = (int)($row['id'] ?? 0);
    $ids[$p['slug']] = $id;

    $r = Markdown::render($p['md']);
    DB::proc('sp_revision_create', [$id, $lang, $adminId, $p['title'], $p['md'], $r['html'], $r['text'], 'Seeded', 'human', 0, $lang]);
    DB::procOne('sp_page_rename', [$id, $p['slug'], $p['title'], 'published', $lang, $lang]);
    DB::exec('sp_locale_status', [$id, $lang, 'published']);

    echo "  page: {$p['title']}\n";
}

DB::exec('sp_setting_set', ['home_space', 'handbook']);

echo "\nDone. Start the site and sign in:\n";
echo "  php -S localhost:8080 -t public public/index.php\n";
