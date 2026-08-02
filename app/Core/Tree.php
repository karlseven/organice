<?php
declare(strict_types=1);

namespace Core;

/**
 * Turns the flat page rows from sp_page_tree into the nested structure the
 * sidebar renders.
 *
 * Done here rather than in SQL because the flat rows are also what the editor's
 * "move page" picker and the prev/next footer need, and fetching the tree once
 * per request in one shape beats three queries in three shapes.
 */
final class Tree
{
    /**
     * @param array<int,array<string,mixed>> $rows sp_page_tree output
     * @return array<int,array<string,mixed>> roots, each with a 'children' key
     */
    public static function build(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[(int)$r['id']] = $r;
        }

        $roots = [];
        foreach ($byId as $id => $_) {
            $pid = (int)($byId[$id]['parent_id'] ?? 0);
            /* A child whose parent was filtered out (a draft parent, for a
               reader who cannot see drafts) is promoted to a root rather than
               dropped — losing a published page because of its parent's status
               would be silent and very confusing. */
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['children'][] =& $byId[$id];
            } else {
                $roots[] =& $byId[$id];
            }
        }
        unset($r);

        return $roots;
    }

    /**
     * Depth-first flatten, in reading order — the sequence the prev/next links
     * at the bottom of a page walk through.
     *
     * @param array<int,array<string,mixed>> $rows sp_page_tree output
     * @return array<int,array<string,mixed>>
     */
    public static function ordered(array $rows): array
    {
        $out = [];
        $walk = static function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $n) {
                $children = $n['children'];
                unset($n['children']);
                $out[] = $n;
                $walk($children);
            }
        };
        $walk(self::build($rows));
        return $out;
    }

    /**
     * The ancestor chain of a page, root first — the breadcrumb.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function crumbs(array $rows, int $pageId): array
    {
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;

        $chain = [];
        $id = $pageId;
        $guard = 0;
        while (isset($byId[$id]) && $guard++ < 32) {
            array_unshift($chain, $byId[$id]);
            $id = (int)($byId[$id]['parent_id'] ?? 0);
        }
        return $chain;
    }
}
