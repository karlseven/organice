<?php
declare(strict_types=1);

namespace Controllers;

use Core\Auth;
use Core\I18n;
use Core\Site;
use Core\DB;
use Core\Request;
use Core\Throttle;
use Core\View;

final class SearchController
{
    private const LIMIT = 40;

    public function index(): void
    {
        /* Reachable without signing in, and every call runs a FULLTEXT query.
           Generous enough that a person browsing never notices. */
        Throttle::guard('search', 60, 60);

        $q = Request::query('q');
        View::render('search/index', [
            'title'   => $q === '' ? 'Search' : 'Search: ' . $q,
            'q'       => $q,
            'results' => $q === '' ? [] : $this->run($q, self::LIMIT),
        ]);
    }

    /** JSON for the header's search-as-you-type box. */
    public function api(): void
    {
        /* Higher ceiling than the full page: this is the as-you-type box, so a
           single person legitimately makes several calls per sentence. */
        Throttle::guard('search_api', 180, 60);

        $q = Request::query('q');
        if (mb_strlen($q) < 2) json_out(['results' => []]);

        $rows = $this->run($q, 8);
        json_out(['results' => array_map(static fn(array $r): array => [
            'title' => $r['title'],
            'space' => $r['space_title'],
            'url'   => Site::pageUrl((string)$r['space_slug'], (string)$r['path']),
            'excerpt' => mb_substr((string)$r['excerpt'], 0, 140),
        ], $rows)]);
    }

    /** @return array<int,array<string,mixed>> */
    private function run(string $q, int $limit): array
    {
        $bool = self::booleanQuery($q);
        if ($bool === '') return [];

        return DB::proc('sp_search', [
            $bool,
            (int)Request::query('space', '0'),
            Auth::id(),
            Auth::isAdmin() ? 1 : 0,
            $limit,
            I18n::current(),
        ]);
    }

    /**
     * A typed phrase into a MySQL boolean-mode query.
     *
     * The operators (+ - > < ( ) ~ * " @) are stripped rather than passed
     * through: a stray '-' or an unbalanced quote from ordinary typing is a
     * syntax error to MySQL, and a reader searching for "cost-per-unit" should
     * not get an empty page because the hyphen was read as NOT.
     *
     * Each remaining word gets a trailing * so "instal" finds "installation",
     * which is what makes the header's as-you-type box usable.
     */
    private static function booleanQuery(string $q): string
    {
        $q = preg_replace('/[+\-><()~*"@]+/', ' ', $q) ?? '';
        $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $min   = I18n::usesNgram(I18n::current()) ? 2 : 3;
        $terms = [];
        foreach ($words as $w) {
            /* Minimum term length depends on the parser. The default one has
               innodb_ft_min_token_size = 3; the ngram parser used for Thai,
               Japanese and Chinese tokenises in PAIRS, so a 3-character floor
               there would throw away most real queries in those languages. */
            if (mb_strlen($w) < $min) continue;
            $terms[] = $w . '*';
        }
        return implode(' ', array_slice($terms, 0, 10));
    }
}
