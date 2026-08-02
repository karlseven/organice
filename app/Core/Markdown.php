<?php
declare(strict_types=1);

namespace Core;

/**
 * Markdown → HTML, plus the table of contents and the plain-text body used for
 * search. One pass produces all three so a save cannot store HTML that
 * disagrees with its own search index.
 *
 * A CommonMark subset — headings, fenced code, lists, tables, quotes, rules,
 * links, images, emphasis — with the two docs-specific extensions this site
 * actually needs:
 *
 *   :::info Optional title      callouts (info | tip | warning | danger | note)
 *   ...
 *   :::
 *
 * Raw HTML in the source is ESCAPED, never passed through. That is the whole
 * reason a hand-rolled parser is safe to store rendered output from: there is
 * no path by which author input reaches the page as markup, so no sanitiser is
 * needed after the fact. If pass-through HTML is ever wanted, it must go
 * through an allow-list here — not by relaxing this rule.
 *
 * Tabs and code-groups are the obvious next extensions; they slot in as
 * additional ::: container types in block(), see docs/PLAN.md.
 */
final class Markdown
{
    /** @return array{html:string, toc:array<int,array{level:int,text:string,id:string}>, text:string} */
    public static function render(string $md): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $md = str_replace("\t", '    ', $md);

        $self = new self();
        $html = $self->blocks(explode("\n", $md));

        return [
            'html' => $self->restoreCode($html),
            'toc'  => $self->toc,
            'text' => self::plain($md),
        ];
    }

    /** @var array<int,array{level:int,text:string,id:string}> */
    private array $toc = [];
    /** @var array<string,bool> anchor ids already used on this page */
    private array $anchors = [];
    /** @var array<int,string> rendered code blocks, held out of the inline pass */
    private array $codeStore = [];

    // -----------------------------------------------------------------------
    // block level
    // -----------------------------------------------------------------------

    /** @param array<int,string> $lines */
    private function blocks(array $lines): string
    {
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') { $i++; continue; }

            // ---- fenced code ------------------------------------------------
            if (preg_match('/^\s*(`{3,}|~{3,})\s*([\w+-]*)\s*(.*)$/', $line, $m)) {
                $fence = $m[1][0];
                $len   = strlen($m[1]);
                $lang  = $m[2];
                $title = trim($m[3]);
                $body  = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*' . preg_quote($fence, '/') . '{' . $len . ',}\s*$/', $lines[$i])) {
                    $body[] = $lines[$i++];
                }
                $i++; // closing fence (or EOF — an unclosed fence still renders)
                $out[] = $this->codeBlock(implode("\n", $body), $lang, $title);
                continue;
            }

            // ---- ::: containers ----------------------------------------------
            if (preg_match('/^:::\s*(info|tip|note|warning|danger|tabs|details|cards|steps)\s*(.*)$/i', $line, $m)) {
                $kind  = strtolower($m[1]);
                $title = trim($m[2]);
                $body  = $this->container($lines, $i);   // advances $i past the close

                /* A switch rather than `match`: match is PHP 8.0 syntax and this
                   app runs on 7.4 upward. Behaviour is identical here because
                   $kind is already lowercased and compared to strings, so the
                   loose comparison switch performs has nothing to get wrong. */
                switch ($kind) {
                    case 'tabs':    $out[] = $this->tabs($body); break;
                    case 'details': $out[] = $this->details($body, $title); break;
                    case 'cards':   $out[] = $this->cards($body); break;
                    case 'steps':   $out[] = $this->steps($body); break;
                    default:        $out[] = $this->callout($body, $kind, $title);
                }
                continue;
            }

            // ---- @embed https://... -------------------------------------------
            if (preg_match('/^@embed\s+(\S+)\s*(.*)$/i', $line, $m)) {
                $out[] = Embed::render($m[1], trim($m[2]));
                $i++;
                continue;
            }

            // ---- heading ----------------------------------------------------
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = $this->inline($m[2]);
                $id    = Slug::anchor(strip_tags($text), $this->anchors);
                /* h2/h3 only in the TOC: h1 is the page title and h4+ is detail
                   that would make the right-hand rail longer than the page. */
                if ($level === 2 || $level === 3) {
                    $this->toc[] = ['level' => $level, 'text' => strip_tags($text), 'id' => $id];
                }
                $out[] = sprintf(
                    '<h%d id="%s">%s<a class="anchor" href="#%s" aria-label="Link to this section">#</a></h%d>',
                    $level, e($id), $text, e($id), $level
                );
                $i++;
                continue;
            }

            // ---- horizontal rule --------------------------------------------
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // ---- table -------------------------------------------------------
            if ($this->startsTable($lines, $i)) {
                $rows = [];
                $head = $line;
                $align = $lines[$i + 1];
                $i += 2;
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $lines[$i++];
                }
                $out[] = $this->table($head, $align, $rows);
                continue;
            }

            // ---- blockquote ---------------------------------------------------
            if (preg_match('/^\s*>\s?/', $line)) {
                $body = [];
                while ($i < $n && (preg_match('/^\s*>\s?/', $lines[$i]) || trim($lines[$i]) !== '')) {
                    if (trim($lines[$i]) === '') break;
                    $body[] = preg_replace('/^\s*>\s?/', '', $lines[$i]) ?? '';
                    $i++;
                }
                $out[] = '<blockquote>' . $this->blocks($body) . '</blockquote>';
                continue;
            }

            // ---- list ----------------------------------------------------------
            if (preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $line, $m0)) {
                $baseIndent  = strlen($m0[1]);
                $baseOrdered = ctype_digit($m0[2][0]);

                /* A list of a DIFFERENT type at the same level starts a new
                   list rather than continuing this one. Without this check a
                   bullet list followed by a numbered list merged into a single
                   list that took whichever type came last — so
                   "- one / - two" then "1. first / 2. second" rendered as one
                   <ol> of four items, on the published page as well as here. */
                $switches = static function (string $l) use ($baseIndent, $baseOrdered): bool {
                    if (!preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $l, $m)) return false;
                    return strlen($m[1]) <= $baseIndent && ctype_digit($m[2][0]) !== $baseOrdered;
                };

                $body = [];
                while ($i < $n) {
                    $l = $lines[$i];
                    // a blank line only ends the list if the next line is not still in it
                    if (trim($l) === '') {
                        $next = $lines[$i + 1] ?? '';
                        if (!preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $next)
                            && !preg_match('/^\s{2,}\S/', $next)) break;
                        if ($switches($next)) break;
                        $body[] = '';
                        $i++;
                        continue;
                    }
                    if ($switches($l)) break;
                    if (!preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $l) && !preg_match('/^\s{2,}\S/', $l)) break;
                    $body[] = $l;
                    $i++;
                }
                $out[] = $this->list($body, 0);
                continue;
            }

            // ---- paragraph --------------------------------------------------------
            $para = [];
            while ($i < $n && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6}\s|:::|\s*>|\s*(`{3,}|~{3,}))/', $lines[$i])
                && !preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $lines[$i])
                /* A table may interrupt a paragraph. Without this the paragraph
                   swallowed the header and delimiter rows, the table block never
                   got a chance, and a table written directly under a line of
                   text came out as "Text. | A | B | | --- | --- |" — which is
                   exactly what the toolbar's table button produced, because it
                   inserts at the caret with no blank line before it. */
                && !$this->startsTable($lines, $i)) {
                $para[] = $lines[$i++];
            }
            if ($para === []) { $para[] = $lines[$i++]; }
            $out[] = '<p>' . $this->inline(implode("\n", $para)) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * The body of a `:::` container, with $i left just past the closing fence.
     *
     * Depth-counted rather than "read to the next `:::`", because a callout
     * inside a tab is a normal thing to write and the naive version ends the
     * outer container on the inner one's close — silently dropping the rest of
     * the tab set into the page as loose text.
     *
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private function container(array $lines, int &$i): array
    {
        $depth = 1;
        $body  = [];
        $n     = count($lines);
        $i++;   // step over the opening fence

        while ($i < $n) {
            $l = $lines[$i];
            if (preg_match('/^:::\s*\S/', $l)) {
                $depth++;
            } elseif (preg_match('/^:::\s*$/', $l)) {
                $depth--;
                if ($depth === 0) { $i++; break; }
            }
            $body[] = $l;
            $i++;
        }
        return $body;
    }

    /**
     * A tab set. Panels are separated by `=== Label` lines:
     *
     *     :::tabs
     *     === macOS
     *     ...
     *     === Windows
     *     ...
     *     :::
     *
     * A code group is just this with one fenced block per tab — no separate
     * syntax for it, because they are the same thing to a reader.
     *
     * Panels are all rendered into the HTML and hidden with an attribute rather
     * than fetched on demand: it keeps the whole page in one document for
     * search engines, printing and in-page find, which matters more here than
     * the few bytes saved.
     *
     * @param array<int,string> $lines
     */
    private function tabs(array $lines): string
    {
        $panels = $this->splitPanels($lines);

        // no `===` lines at all: treat the whole thing as ordinary content
        // rather than rendering an empty tab strip
        if ($panels === []) return $this->blocks($lines);

        /* Unique per document so two tab sets on one page do not share ids —
           the labels are a <button> group wired by index, and duplicate ids
           would make the second set control the first. */
        $gid = 'tabs-' . (++$this->tabCount);

        $strip = '';
        $body  = '';
        foreach ($panels as $k => [$name, $content]) {
            $first = $k === 0;
            $pid   = $gid . '-p' . $k;

            $strip .= '<button class="tab-btn' . ($first ? ' active' : '') . '" type="button"'
                    . ' role="tab" aria-selected="' . ($first ? 'true' : 'false') . '"'
                    . ' aria-controls="' . $pid . '" data-tab="' . $k . '">'
                    . e($name) . '</button>';

            $body .= '<div class="tab-panel" id="' . $pid . '" role="tabpanel" data-tab="' . $k . '"'
                   . ($first ? '' : ' hidden') . '>' . $this->blocks($content) . '</div>';
        }

        return '<div class="tabs" data-tabs="' . $gid . '">'
             . '<div class="tab-strip" role="tablist">' . $strip . '</div>'
             . $body . '</div>';
    }

    /** @var int tab sets seen so far, for unique ids */
    private int $tabCount = 0;

    /** @param array<int,string> $body */
    private function callout(array $body, string $kind, string $title): string
    {
        $head = $title !== ''
            ? '<p class="callout-title">' . $this->inline($title) . '</p>'
            : '';
        return '<div class="callout callout-' . $kind . '" role="note">'
             . $head . $this->blocks($body) . '</div>';
    }

    /**
     * A collapsed section.
     *
     *     :::details What happens if I skip this?
     *     ...
     *     :::
     *
     * Built on <details>/<summary> rather than a div plus JavaScript. That
     * gets keyboard support, the correct ARIA semantics, and — the reason that
     * actually matters for documentation — in-page find still reaches the
     * collapsed text in browsers that implement `hidden=until-found`.
     *
     * @param array<int,string> $body
     */
    private function details(array $body, string $title): string
    {
        $label = $title !== '' ? $this->inline($title) : 'Details';
        return '<details class="details-block"><summary>' . $label . '</summary>'
             . '<div class="details-body">' . $this->blocks($body) . '</div></details>';
    }

    /**
     * A grid of link cards, one per `=== Title` panel, same separator as tabs.
     *
     *     :::cards
     *     === Installing
     *     How to get it running. [Read](/s/handbook/install)
     *     :::
     *
     * If a panel's body contains exactly one link, the WHOLE card becomes that
     * link — a card that looks clickable but only responds on four words of
     * text is a small, constant irritation.
     *
     * @param array<int,string> $body
     */
    private function cards(array $body): string
    {
        $panels = $this->splitPanels($body);
        if ($panels === []) return $this->blocks($body);

        $html = '';
        foreach ($panels as [$title, $content]) {
            $inner = $this->blocks($content);

            // exactly one link in the card → promote it to the card itself
            $href = null;
            if (preg_match_all('/<a class="[^"]*"?\s*href="([^"]+)"|<a href="([^"]+)"/', $inner, $m) === 1) {
                $href = $m[1][0] !== '' ? $m[1][0] : $m[2][0];
                $inner = preg_replace('#<a\b[^>]*>(.*?)</a>#s', '$1', $inner) ?? $inner;
            }

            $card = '<h3 class="card-title">' . $this->inline($title) . '</h3>'
                  . '<div class="card-body">' . $inner . '</div>';

            $html .= $href !== null
                ? '<a class="card card-link" href="' . $href . '">' . $card . '</a>'
                : '<div class="card-item">' . $card . '</div>';
        }

        return '<div class="card-grid">' . $html . '</div>';
    }

    /**
     * Numbered steps.
     *
     * An ordered list would render the same content, but the numbers here are
     * generated by CSS counters against a connecting rail, which is what makes
     * a long install sequence readable. The markup stays an <ol> so it degrades
     * to a numbered list with styles off and reads correctly to a screen
     * reader.
     *
     * @param array<int,string> $body
     */
    private function steps(array $body): string
    {
        $panels = $this->splitPanels($body);
        if ($panels === []) return $this->blocks($body);

        $html = '';
        foreach ($panels as [$title, $content]) {
            $html .= '<li class="step"><p class="step-title">' . $this->inline($title) . '</p>'
                   . '<div class="step-body">' . $this->blocks($content) . '</div></li>';
        }
        return '<ol class="steps">' . $html . '</ol>';
    }

    /**
     * Split a container body on `=== Label` lines. Shared by tabs, cards and
     * steps so all three take the same shape — one separator to learn, not
     * three.
     *
     * @param array<int,string> $lines
     * @return array<int,array{0:string,1:array<int,string>}>
     */
    private function splitPanels(array $lines): array
    {
        $panels = [];
        $label  = null;
        $buf    = [];

        foreach ($lines as $l) {
            if (preg_match('/^===\s+(.+?)\s*$/', $l, $m)) {
                if ($label !== null) $panels[] = [$label, $buf];
                $label = $m[1];
                $buf   = [];
                continue;
            }
            if ($label !== null) $buf[] = $l;
        }
        if ($label !== null) $panels[] = [$label, $buf];

        return $panels;
    }

    /**
     * Nested lists from an indentation stack. Items two or more spaces deeper
     * than their parent become a sublist; anything shallower closes back out.
     *
     * @param array<int,string> $lines
     */
    private function list(array $lines, int $indent): string
    {
        $ordered = false;
        $items   = [];   // each item is an array of its own lines
        $current = null;

        foreach ($lines as $l) {
            if (preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/', $l, $m) && strlen($m[1]) <= $indent + 1) {
                if ($current !== null) $items[] = $current;
                $ordered = !in_array($m[2], ['-', '*', '+'], true);
                $current = [$m[3]];
                continue;
            }
            if ($current === null) continue;          // stray line before any item
            // continuation or nested content: strip one level of indentation
            $current[] = preg_replace('/^ {1,' . ($indent + 2) . '}/', '', $l) ?? $l;
        }
        if ($current !== null) $items[] = $current;
        if ($items === []) return '';

        $html = '';
        foreach ($items as $item) {
            $first = array_shift($item) ?? '';
            $rest  = array_filter($item, static fn($x) => trim($x) !== '');

            /* A task-list checkbox is stripped from the source text and
               rendered disabled: this is documentation, not a form — a reader
               ticking it would change nothing while implying it had. */
            $box = '';
            if (preg_match('/^\[( |x|X)\]\s+(.*)$/s', $first, $cm)) {
                $checked = strtolower($cm[1]) === 'x';
                $box = '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . '> ';
                $first = $cm[2];
            }

            $inner = $box . $this->inline($first);
            if ($rest !== []) {
                // the remainder of the item is a block context of its own, which
                // is what makes nested lists and indented code inside an item work
                $inner .= "\n" . $this->blocks(array_values($item));
            }

            $html .= '<li' . ($box !== '' ? ' class="task"' : '') . '>' . $inner . '</li>';
        }

        $tag = $ordered ? 'ol' : 'ul';
        return "<$tag>$html</$tag>";
    }

    /** @param array<int,string> $rows */
    /**
     * Does a table begin at $lines[$i]?
     *
     * A table is a row containing a pipe followed by a delimiter row — the
     * delimiter is what distinguishes it from a paragraph that merely happens to
     * contain a pipe character.
     *
     * Its own method because two places need the same answer: the block loop,
     * which builds the table, and the paragraph loop, which must stop before it.
     */
    private function startsTable(array $lines, int $i): bool
    {
        $line = $lines[$i] ?? '';
        $next = $lines[$i + 1] ?? '';

        return str_contains($line, '|')
            && str_contains($next, '-')
            && preg_match('/^\s*\|?[\s:-]*-[\s:|-]*\|?\s*$/', $next) === 1;
    }

    private function table(string $head, string $align, array $rows): string
    {
        $cells = static fn(string $r): array => array_map(
            'trim',
            explode('|', trim(trim($r), '|'))
        );

        $aligns = [];
        foreach ($cells($align) as $a) {
            $left  = str_starts_with($a, ':');
            $right = str_ends_with($a, ':');
            $aligns[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }

        $th = '';
        foreach ($cells($head) as $k => $c) {
            $cls = ($aligns[$k] ?? '') !== '' ? ' class="ta-' . $aligns[$k] . '"' : '';
            $th .= '<th' . $cls . '>' . $this->inline($c) . '</th>';
        }

        $tb = '';
        foreach ($rows as $r) {
            $tb .= '<tr>';
            foreach ($cells($r) as $k => $c) {
                $cls = ($aligns[$k] ?? '') !== '' ? ' class="ta-' . $aligns[$k] . '"' : '';
                $tb .= '<td' . $cls . '>' . $this->inline($c) . '</td>';
            }
            $tb .= '</tr>';
        }

        /* The wrapper is what lets a wide table scroll inside the article
           instead of pushing the whole page sideways on a phone. */
        return '<div class="table-wrap"><table><thead><tr>' . $th . '</tr></thead>'
             . '<tbody>' . $tb . '</tbody></table></div>';
    }

    private function codeBlock(string $code, string $lang, string $title): string
    {
        $langClass = $lang !== '' ? ' class="language-' . e($lang) . '"' : '';
        $head = $title !== '' ? '<div class="code-title">' . e($title) . '</div>' : '';

        /* Highlighted here, at save time, so a page view never runs either this
           parser or the tokenizer. Highlight::code() escapes everything it
           emits; when it has no rules for the language it returns plain escaped
           source, so an unknown language degrades to exactly what it was. */
        $body = Highlight::code($code, $lang);

        $html = '<div class="code-block" data-lang="' . e($lang) . '">' . $head
              /* Deliberately EMPTY, with no tooltip text either. This markup is
                 cached per revision, so any wording baked in here would be
                 frozen in whichever language the page happened to be saved in
                 and would show German copy on the Thai page. app.js fills in
                 both the label and the tip from the reading language. */
              . '<button class="code-copy" type="button" data-copy></button>'
              . '<pre><code' . $langClass . '>' . $body . "</code></pre></div>";

        // held out of the inline pass so ** and _ inside code stay literal
        $this->codeStore[] = $html;
        return "\x00CODE" . (count($this->codeStore) - 1) . "\x00";
    }

    private function restoreCode(string $html): string
    {
        return preg_replace_callback(
            '/\x00CODE(\d+)\x00/',
            fn(array $m): string => $this->codeStore[(int)$m[1]] ?? '',
            $html
        ) ?? $html;
    }

    // -----------------------------------------------------------------------
    // inline level
    // -----------------------------------------------------------------------

    private function inline(string $text): string
    {
        // Code spans come out first, for the same reason as fenced blocks.
        $spans = [];
        $text = preg_replace_callback('/(`+)(.+?)\1/s', static function (array $m) use (&$spans): string {
            $spans[] = '<code>' . e(trim($m[2])) . '</code>';
            return "\x01" . (count($spans) - 1) . "\x01";
        }, $text) ?? $text;

        // Everything that is left is author text, and it is escaped HERE, once.
        // No rule below ever re-introduces an unescaped author string.
        $text = e($text);

        // images before links — ![x](y) would otherwise match the link rule
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
            function (array $m): string {
                $src = self::safeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                if ($src === null) return $m[0];
                $title = isset($m[3]) && $m[3] !== '' ? ' title="' . e($m[3]) . '"' : '';
                /* loading=lazy and no-referrer: an author may point at an image
                   on another host, and a reader should not announce their IP
                   and the page they are on to it before scrolling that far. */
                return '<img src="' . e($src) . '" alt="' . $m[1] . '"' . $title
                     . ' loading="lazy" referrerpolicy="no-referrer">';
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
            function (array $m): string {
                $href = self::safeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                if ($href === null) return $m[1];   // drop the link, keep the words
                $ext  = preg_match('#^https?://#i', $href) === 1;
                $rel  = $ext ? ' rel="noopener noreferrer" target="_blank"' : '';
                $title = isset($m[3]) && $m[3] !== '' ? ' title="' . e($m[3]) . '"' : '';
                return '<a href="' . e($href) . '"' . $rel . $title . '>' . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        // bare URLs, but not ones already inside an href="" we just wrote
        $text = preg_replace(
            '#(?<!["\'>=])\bhttps?://[^\s<>"\']+#i',
            '<a href="$0" rel="noopener noreferrer" target="_blank">$0</a>',
            $text
        ) ?? $text;

        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(?=\S)(.+?)(?<=\S)__/s',     '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/s',   '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/==(?=\S)(.+?)(?<=\S)==/s', '<mark>$1</mark>', $text) ?? $text;

        // a hard break is two trailing spaces, as in CommonMark
        $text = preg_replace('/ {2,}\n/', "<br>\n", $text) ?? $text;

        return preg_replace_callback(
            '/\x01(\d+)\x01/',
            static fn(array $m): string => $spans[(int)$m[1]] ?? '',
            $text
        ) ?? $text;
    }

    /**
     * Null for anything that is not a plain document link. The allow-list is
     * deliberate: javascript:, data: and vbscript: are the whole attack, and an
     * allow-list cannot be defeated by a new scheme or by case and whitespace
     * tricks the way a deny-list can.
     */
    private static function safeUrl(string $url): ?string
    {
        $u = trim($url);
        if ($u === '') return null;
        // strip control characters used to smuggle a scheme past a naive check
        $u = preg_replace('/[\x00-\x20]/', '', $u) ?? $u;

        if (preg_match('#^(https?:|mailto:)#i', $u)) return $u;
        if ($u[0] === '/' || $u[0] === '#' || str_starts_with($u, './') || str_starts_with($u, '../')) return $u;
        // no scheme at all — a sibling page
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $u)) return $u;
        return null;
    }

    // -----------------------------------------------------------------------
    // search text
    // -----------------------------------------------------------------------

    /**
     * Markdown stripped to prose, for the FULLTEXT column. Code blocks are
     * dropped entirely: a search for "user" should find the page that explains
     * users, not every page with a `user` variable in a snippet.
     */
    public static function plain(string $md): string
    {
        $t = preg_replace('/^\s*(`{3,}|~{3,}).*?^\s*\1.*?$/ms', ' ', $md) ?? $md;
        $t = preg_replace('/`[^`]*`/', ' ', $t) ?? $t;
        $t = preg_replace('/^:::.*$/m', ' ', $t) ?? $t;
        $t = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t;   // alt text only
        $t = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $t) ?? $t;    // link text only
        $t = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $t) ?? $t;
        $t = preg_replace('/^\s*>\s?/m', '', $t) ?? $t;
        $t = preg_replace('/^\s*([-*+]|\d{1,9}[.)])\s+/m', '', $t) ?? $t;
        $t = preg_replace('/[*_~=|#]+/', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * The on-page contents rail, read back out of already-rendered HTML.
     *
     * Re-derived here rather than stored alongside the revision: the ids and
     * the anchors are already in the cached HTML, so a stored copy would be a
     * second source of truth that could drift from it after an edit.
     *
     * @return array<int,array{level:int,text:string,id:string}>
     */
    public static function tocFromHtml(string $html): array
    {
        if (!preg_match_all('/<h([23]) id="([^"]+)">(.*?)<a class="anchor"/s', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $h) {
            $out[] = [
                'level' => (int)$h[1],
                'id'    => html_entity_decode($h[2], ENT_QUOTES, 'UTF-8'),
                'text'  => trim(html_entity_decode(strip_tags($h[3]), ENT_QUOTES, 'UTF-8')),
            ];
        }
        return $out;
    }

    /** First ~200 characters of prose, for search results and link previews. */
    public static function excerpt(string $md, int $len = 200): string
    {
        $t = self::plain($md);
        if (mb_strlen($t) <= $len) return $t;
        $cut = mb_substr($t, 0, $len);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false ? mb_substr($cut, 0, $sp) : $cut, " ,.;:") . '…';
    }
}
