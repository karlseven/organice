<?php
declare(strict_types=1);

namespace Core;

/**
 * Line diff between two revisions.
 *
 * Longest-common-subsequence, computed on hashes rather than on the lines
 * themselves so the inner loop compares integers. The table is O(n*m), which is
 * fine for prose — a documentation page is hundreds of lines, not hundreds of
 * thousands — but it is bounded anyway (see MAX_LINES) so that a pasted log
 * file cannot turn opening the history panel into a memory event.
 */
final class Diff
{
    private const MAX_LINES = 4000;

    /**
     * @return array{
     *   rows: array<int,array{type:string, old:?int, new:?int, text:string}>,
     *   added: int, removed: int, truncated: bool
     * }
     */
    public static function lines(string $before, string $after): array
    {
        $a = preg_split('/\R/', $before) ?: [];
        $b = preg_split('/\R/', $after) ?: [];

        $truncated = count($a) > self::MAX_LINES || count($b) > self::MAX_LINES;
        if ($truncated) {
            $a = array_slice($a, 0, self::MAX_LINES);
            $b = array_slice($b, 0, self::MAX_LINES);
        }

        $rows = self::walk($a, $b, self::lcs($a, $b));

        return [
            'rows'      => $rows,
            'added'     => count(array_filter($rows, static fn(array $r): bool => $r['type'] === 'add')),
            'removed'   => count(array_filter($rows, static fn(array $r): bool => $r['type'] === 'del')),
            'truncated' => $truncated,
        ];
    }

    /**
     * LCS length table.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array<int,int>>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // hash once; string comparison in the O(n*m) loop is the whole cost
        $ha = array_map('crc32', $a);
        $hb = array_map('crc32', $b);

        $t = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                /* The hash decides equality but the STRINGS are confirmed:
                   crc32 collides, and a collision here would silently report
                   two different lines as unchanged. */
                $t[$i][$j] = ($ha[$i] === $hb[$j] && $a[$i] === $b[$j])
                    ? $t[$i + 1][$j + 1] + 1
                    : max($t[$i + 1][$j], $t[$i][$j + 1]);
            }
        }
        return $t;
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @param array<int,array<int,int>> $t
     * @return array<int,array{type:string, old:?int, new:?int, text:string}>
     */
    private static function walk(array $a, array $b, array $t): array
    {
        $rows = [];
        $i = 0;
        $j = 0;
        $n = count($a);
        $m = count($b);

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $rows[] = ['type' => 'same', 'old' => $i + 1, 'new' => $j + 1, 'text' => $a[$i]];
                $i++; $j++;
            } elseif ($t[$i + 1][$j] >= $t[$i][$j + 1]) {
                $rows[] = ['type' => 'del', 'old' => $i + 1, 'new' => null, 'text' => $a[$i]];
                $i++;
            } else {
                $rows[] = ['type' => 'add', 'old' => null, 'new' => $j + 1, 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) { $rows[] = ['type' => 'del', 'old' => ++$i, 'new' => null, 'text' => $a[$i - 1]]; }
        while ($j < $m) { $rows[] = ['type' => 'add', 'old' => null, 'new' => ++$j, 'text' => $b[$j - 1]]; }

        return $rows;
    }

    /**
     * Drop long stretches of unchanged lines, keeping $context either side of
     * every change — the same reason `diff -u` does it. Without this, a
     * one-word fix on a long page is a screen of identical text with the change
     * somewhere in the middle.
     *
     * @param array<int,array{type:string, old:?int, new:?int, text:string}> $rows
     * @return array<int,array{type:string, old:?int, new:?int, text:string}>
     */
    public static function collapse(array $rows, int $context = 3): array
    {
        $keep = [];
        foreach ($rows as $k => $r) {
            if ($r['type'] === 'same') continue;
            for ($x = $k - $context; $x <= $k + $context; $x++) {
                if (isset($rows[$x])) $keep[$x] = true;
            }
        }

        $out = [];
        $gap = false;
        foreach ($rows as $k => $r) {
            if (isset($keep[$k])) {
                $out[] = $r;
                $gap = false;
            } elseif (!$gap) {
                $out[] = ['type' => 'gap', 'old' => null, 'new' => null, 'text' => '⋯'];
                $gap = true;
            }
        }
        return $out;
    }
}
