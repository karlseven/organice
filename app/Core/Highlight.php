<?php
declare(strict_types=1);

namespace Core;

/**
 * Syntax highlighting, done on the server at save time.
 *
 * Deliberately not a client-side library. Highlighting runs once per save
 * rather than once per reader, the markup is in the HTML that search engines
 * and reader-mode see, and there is no 200 KB script on the critical path of
 * every documentation page. The cost is that adding a language means editing
 * this file — an acceptable trade for a docs site, where the same handful of
 * languages appear over and over.
 *
 * The approach is one alternation of ordered patterns, matched left to right in
 * a single pass. That ordering is the whole design: comments and strings come
 * FIRST, so a keyword inside a string or a `#` inside a URL is consumed as part
 * of the larger token and never re-examined. A naive "replace all keywords,
 * then replace all strings" pass gets this wrong in both directions.
 *
 * Input is raw source. Output is HTML-escaped with <span class="tok-*">
 * wrappers — nothing here can emit markup that came from the author.
 */
final class Highlight
{
    /** Languages with real rules. Aliases map onto these. */
    private const ALIASES = [
        'js' => 'javascript', 'jsx' => 'javascript', 'ts' => 'javascript',
        'tsx' => 'javascript', 'node' => 'javascript', 'mjs' => 'javascript',
        'sh' => 'bash', 'shell' => 'bash', 'zsh' => 'bash', 'console' => 'bash',
        'yml' => 'yaml',
        'py' => 'python',
        'htm' => 'html', 'xml' => 'html', 'svg' => 'html', 'vue' => 'html',
        'psql' => 'sql', 'mysql' => 'sql',
        'jsonc' => 'json',
        'md' => 'markdown',
        'ps1' => 'powershell', 'dotenv' => 'ini', 'env' => 'ini', 'conf' => 'ini',
    ];

    private const KEYWORDS = [
        'php' => 'abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|empty|enum|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield',
        'javascript' => 'as|async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|export|extends|finally|for|from|function|get|if|implements|import|in|instanceof|interface|let|new|of|private|protected|public|readonly|return|set|static|super|switch|throw|try|type|typeof|var|void|while|with|yield',
        'python' => 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|finally|for|from|global|if|import|in|is|lambda|nonlocal|not|or|pass|raise|return|try|while|with|yield',
        'sql' => 'ADD|ALTER|AND|AS|ASC|BEGIN|BETWEEN|BY|CALL|CASE|CHECK|COLLATE|COLUMN|COMMIT|CONSTRAINT|CREATE|CROSS|DECLARE|DEFAULT|DELETE|DESC|DISTINCT|DROP|ELSE|END|EXISTS|FOREIGN|FROM|FULL|GROUP|HAVING|IF|IN|INDEX|INNER|INSERT|INTO|IS|JOIN|KEY|LEFT|LIKE|LIMIT|NOT|NULL|ON|OR|ORDER|OUTER|PRIMARY|PROCEDURE|REFERENCES|RETURNS|RIGHT|ROLLBACK|SELECT|SET|TABLE|THEN|TRUNCATE|UNION|UNIQUE|UPDATE|VALUES|VIEW|WHEN|WHERE|WHILE|WITH',
        'bash' => 'break|case|cd|continue|do|done|echo|elif|else|esac|exit|export|fi|for|function|if|in|local|read|return|set|shift|source|then|unset|until|while',
        'go' => 'break|case|chan|const|continue|default|defer|else|fallthrough|for|func|go|goto|if|import|interface|map|package|range|return|select|struct|switch|type|var',
        'powershell' => 'begin|break|catch|class|continue|do|dynamicparam|else|elseif|end|exit|filter|finally|for|foreach|function|if|in|param|process|return|switch|throw|trap|try|until|while',
    ];

    private const LITERALS = [
        'php'        => 'true|false|null|TRUE|FALSE|NULL|self|parent|this',
        'javascript' => 'true|false|null|undefined|NaN|Infinity|this',
        'python'     => 'True|False|None|self',
        'sql'        => 'TRUE|FALSE|NULL',
        'go'         => 'true|false|nil|iota',
        'bash'       => 'true|false',
        'powershell' => 'true|false|null',
    ];

    /** Escaped HTML with token spans, or plain escaped code for unknown languages. */
    public static function code(string $code, string $lang): string
    {
        $lang = strtolower(trim($lang));
        $lang = self::ALIASES[$lang] ?? $lang;

        $rules = self::rules($lang);
        if ($rules === []) return e($code);

        $out = '';
        $offset = 0;
        $len = strlen($code);
        $pattern = '/' . implode('|', array_map(
            static fn(string $k, string $re): string => '(?P<' . $k . '>' . $re . ')',
            array_keys($rules), array_values($rules)
        )) . '/S' . (in_array($lang, ['sql', 'powershell'], true) ? 'i' : '');

        /* One pass, left to right. Everything between two matches is plain text
           and is escaped as-is; each match is escaped and wrapped. Because the
           alternation is ordered, the first branch that can start at a position
           wins, which is what keeps keywords inside strings from matching. */
        while ($offset < $len && preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $whole = $m[0][0];
            $at    = $m[0][1];

            if ($at > $offset) $out .= e(substr($code, $offset, $at - $offset));

            $kind = 'plain';
            foreach ($rules as $k => $_) {
                if (isset($m[$k]) && $m[$k][1] !== -1 && $m[$k][0] !== '') { $kind = $k; break; }
            }

            $out .= $kind === 'plain' ? e($whole) : '<span class="tok-' . $kind . '">' . e($whole) . '</span>';

            // a zero-length match would spin forever
            $offset = $at + max(1, strlen($whole));
        }

        if ($offset < $len) $out .= e(substr($code, $offset));
        return $out;
    }

    /**
     * Ordered rules for a language. Order IS the grammar here — see the class
     * comment. Named groups double as the CSS class suffix.
     *
     * @return array<string,string>
     */
    private static function rules(string $lang): array
    {
        $kw  = self::KEYWORDS[$lang] ?? '';
        $lit = self::LITERALS[$lang] ?? '';

        // Languages with no rules of their own fall through to a generic set,
        // which still gets strings, numbers and comments right.
        $known = isset(self::KEYWORDS[$lang])
            || in_array($lang, ['json', 'yaml', 'html', 'css', 'ini', 'markdown', 'diff'], true);
        if (!$known) return [];

        $rules = [];

        // ---- comments first, always ----
        switch ($lang) {
            case 'php':
            case 'javascript':
            case 'go':
            case 'css':
                $rules['comment'] = '\/\*[\s\S]*?\*\/' . ($lang === 'css' ? '' : '|\/\/[^\n]*');
                if ($lang === 'php') $rules['comment'] .= '|#[^\n]*';
                break;
            case 'python':
            case 'yaml':
            case 'bash':
            case 'ini':
                $rules['comment'] = '#[^\n]*';
                break;
            case 'powershell':
                $rules['comment'] = '<#[\s\S]*?#>|#[^\n]*';
                break;
            case 'sql':
                $rules['comment'] = '--[^\n]*|\/\*[\s\S]*?\*\/';
                break;
            case 'html':
                $rules['comment'] = '<!--[\s\S]*?-->';
                break;
            case 'markdown':
                $rules['comment'] = '^\s{0,3}>[^\n]*';
                break;
        }

        // ---- language-specific structure ----
        if ($lang === 'html') {
            /* The tag NAME and the attributes are separate tokens, so
               `<a href="x">` colours the way a reader expects rather than as one
               undifferentiated blob. */
            $rules['string'] = '"[^"]*"|\'[^\']*\'';
            $rules['tag']    = '<\/?[a-zA-Z][\w:-]*|\/?>';
            $rules['attr']   = '[a-zA-Z_:][\w:.-]*(?=\s*=)';
            return $rules;
        }

        if ($lang === 'json') {
            // the key must be tried before the generic string, or every key is
            // just another string
            $rules['key']     = '"(?:[^"\\\\]|\\\\.)*"(?=\s*:)';
            $rules['string']  = '"(?:[^"\\\\]|\\\\.)*"';
            $rules['literal'] = '\btrue\b|\bfalse\b|\bnull\b';
            $rules['number']  = '-?\b\d+(?:\.\d+)?(?:[eE][-+]?\d+)?\b';
            return $rules;
        }

        if ($lang === 'yaml' || $lang === 'ini') {
            $rules['key']     = '^[ \t]*[-\w.$][\w.\- $]*(?=\s*[:=])';
            $rules['string']  = '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'';
            $rules['literal'] = '\b(?:true|false|null|yes|no|on|off)\b';
            $rules['number']  = '\b\d+(?:\.\d+)?\b';
            return $rules;
        }

        if ($lang === 'markdown') {
            $rules['heading'] = '^\s{0,3}#{1,6}[^\n]*';
            $rules['string']  = '`[^`\n]*`';
            $rules['keyword'] = '\*\*[^*\n]+\*\*|__[^_\n]+__';
            $rules['attr']    = '\[[^\]\n]*\]\([^)\n]*\)';
            return $rules;
        }

        if ($lang === 'diff') {
            $rules['added']   = '^\+[^\n]*';
            $rules['removed'] = '^-[^\n]*';
            $rules['meta']    = '^@@[^\n]*';
            return $rules;
        }

        // ---- the general case ----
        $rules['string'] = '"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`';

        if ($lang === 'php' || $lang === 'bash' || $lang === 'powershell') {
            $rules['variable'] = '\$[a-zA-Z_][\w]*';
        }
        if ($lang === 'php') {
            $rules['function'] = '(?<=function\s)[a-zA-Z_]\w*|[a-zA-Z_]\w*(?=\s*\()';
        }
        if ($lang === 'javascript' || $lang === 'python' || $lang === 'go') {
            $rules['function'] = '[a-zA-Z_]\w*(?=\s*\()';
        }
        if ($lang === 'bash') {
            /* The prompt marker is dropped from a copied block by the reader,
               not by us, but colouring it makes it obvious it is not part of
               the command. */
            $rules['meta'] = '^\s*[$#](?=\s)';
        }

        if ($lit !== '') $rules['literal'] = '\b(?:' . $lit . ')\b';
        if ($kw  !== '') $rules['keyword'] = '\b(?:' . $kw . ')\b';

        $rules['number'] = '\b0[xX][0-9a-fA-F]+\b|\b\d+(?:\.\d+)?(?:[eE][-+]?\d+)?\b';

        return $rules;
    }

    /** Does this language have rules? Used to decide whether to label a block. */
    public static function supports(string $lang): bool
    {
        $lang = strtolower(trim($lang));
        $lang = self::ALIASES[$lang] ?? $lang;
        return self::rules($lang) !== [];
    }
}
