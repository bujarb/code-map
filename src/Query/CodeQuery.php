<?php

declare(strict_types=1);

namespace CodeMap\Query;

use CodeMap\Indexer\Indexer;
use CodeMap\Storage\Database;

/**
 * Read side of the index. Every method returns compact plain text meant to be read by an LLM:
 * one fact per line, "path:line" locations, and an explicit hint when nothing matched.
 */
final readonly class CodeQuery
{
    private const array CLASS_KINDS = ['class', 'interface', 'trait', 'enum'];
    /** Top-level TypeScript symbols that short-name lookups, namespace counts and the markdown treat like classes. */
    public const array TS_TYPE_KINDS = ['class', 'interface', 'enum', 'type', 'component', 'function', 'const'];
    private const int COMPACT_THRESHOLD = 40;

    public function __construct(private Database $db)
    {
    }

    public function searchSymbols(string $query, ?string $kind = null, int $limit = 10): string
    {
        $limit = max(1, min($limit, 200));
        $kindSql = null === $kind || '' === $kind ? '' : ' AND s.kind = :kind';
        $params = null === $kind || '' === $kind ? [] : ['kind' => $kind];

        $rows = [];
        // All terms first; if that's thin, top up with symbols matching any term.
        foreach ([' ', ' OR '] as $operator) {
            $match = $this->ftsQuery($query, $operator);
            if ('' === $match || \count($rows) >= $limit) {
                break;
            }
            foreach ($this->db->all(
                "SELECT s.id, s.kind, s.fqn, s.doc, s.start_line, f.path
                 FROM symbols_fts JOIN symbols s ON s.id = symbols_fts.rowid JOIN files f ON f.id = s.file_id
                 WHERE symbols_fts MATCH :match{$kindSql}
                 ORDER BY (s.kind IN {$this->kindList()}) DESC, bm25(symbols_fts, 1.0, 10.0, 2.0)
                 LIMIT {$limit}",
                ['match' => $match, ...$params],
            ) as $row) {
                $rows[$row['id']] ??= $row;
            }
            if (!str_contains($match, ' ')) {
                break; // single term: OR would return the same rows
            }
        }
        $rows = \array_slice(array_values($rows), 0, $limit);
        if ([] === $rows) {
            $rows = $this->db->all(
                "SELECT s.kind, s.fqn, s.doc, s.start_line, f.path
                 FROM symbols s JOIN files f ON f.id = s.file_id
                 WHERE s.fqn LIKE :like ESCAPE '!'{$kindSql}
                 ORDER BY length(s.fqn) LIMIT {$limit}",
                ['like' => '%'.$this->escapeLike($query).'%', ...$params],
            );
        }
        if ([] === $rows) {
            return "No symbols match \"{$query}\".";
        }

        // Docs are cut short here: the result is for picking a symbol, class_outline shows the details.
        return implode("\n", array_map(
            static fn (array $r) => "{$r['kind']} {$r['fqn']}  {$r['path']}:{$r['start_line']}"
                .(null === $r['doc'] ? '' : ' — '.(mb_strlen($r['doc']) > 80 ? mb_substr($r['doc'], 0, 80).'…' : $r['doc'])),
            $rows,
        ));
    }

    /**
     * Outline of a class-like symbol, or of a whole file when given a path ending in .php.
     *
     * Sections with more than COMPACT_THRESHOLD entries list only "name :line" unless $member narrows them down,
     * because full signatures of a 150-method facade cost more context than the question usually needs.
     */
    public function classOutline(string $fqcn, ?string $member = null, bool $includePrivate = true): string
    {
        if (1 === preg_match('/\.(php|[mc]?tsx?)$/i', $fqcn)) {
            return $this->fileOutline($fqcn);
        }
        if (null !== ($ambiguous = $this->ambiguity($fqcn))) {
            return $ambiguous;
        }
        $class = $this->findClass($fqcn);
        if (null === $class) {
            return $this->notFound($fqcn);
        }

        $out = [$class['signature'], "file: {$class['path']}:{$class['start_line']}-{$class['end_line']}"];
        if (null !== $class['doc']) {
            $out[] = 'doc: '.$class['doc'];
        }
        foreach ($this->attributesOf((int) $class['id']) as $attr) {
            $out[] = 'attribute: '.$attr;
        }
        $traits = $this->db->all("SELECT target FROM relations WHERE symbol_id = ? AND kind = 'uses_trait'", [$class['id']]);
        if ([] !== $traits) {
            $out[] = 'uses traits: '.implode(', ', array_column($traits, 'target'));
        }

        $members = $this->db->all(
            'SELECT id, kind, name, signature, doc, visibility, flags, start_line FROM symbols WHERE parent_id = ? ORDER BY start_line',
            [$class['id']],
        );
        $promoted = \count(array_filter($members, static fn (array $m) => str_contains($m['flags'], 'promoted')));
        $filter = null === $member || '' === trim($member) ? null : ltrim(trim($member), '$');
        if (null !== $filter) {
            $members = array_values(array_filter($members, static fn (array $m) => false !== stripos($m['name'], $filter)));
            if ([] === $members) {
                $out[] = "No member of {$class['fqn']} matches \"{$filter}\".";

                return implode("\n", $out);
            }
        }

        $sections = ['case' => 'cases', 'constant' => 'constants', 'property' => 'properties', 'method' => 'methods'];
        foreach ($sections as $kind => $title) {
            $lines = [];
            $names = [];
            foreach ($members as $m) {
                // Promoted properties are the class's injected dependencies, so they're shown even when private.
                if ($m['kind'] !== $kind || (!$includePrivate && 'private' === $m['visibility'] && !str_contains($m['flags'], 'promoted'))) {
                    continue;
                }
                $names[] = ('property' === $kind ? '$' : '').$m['name'].' :'.$m['start_line'];
                // The header line keeps full names; inside members short names halve the size of big classes.
                $signature = Names::shortenAll($m['signature']);
                if ('method' === $m['kind']) {
                    // Under "methods:" the keyword is implied, and public is the common case.
                    $signature = (string) preg_replace(['/\bfunction /', '/^public /'], ['', ''], $signature);
                }
                if ('__construct' === $m['name'] && $promoted > 3 && \strlen($signature) > 200) {
                    // The promoted params are already listed under "properties"; repeating them would double the output.
                    $signature = preg_replace('/\(.*\)/s', "(… {$promoted} promoted properties, see above)", $signature, 1);
                }
                $attrs = $this->attributesOf((int) $m['id']);
                $lines[] = '  '.$signature
                    ."  :{$m['start_line']}"
                    .([] === $attrs ? '' : '  #['.Names::shortenAll(implode('] #[', $attrs)).']')
                    .(null === $m['doc'] ? '' : '  — '.$m['doc']);
            }
            if ([] === $lines) {
                continue;
            }
            if (null === $filter && \count($lines) > self::COMPACT_THRESHOLD) {
                $out[] = "{$title} (".\count($lines).', names only; pass member="part of a name" for signatures):';
                foreach ($this->wrap($names, 150) as $line) {
                    $out[] = '  '.$line;
                }
                continue;
            }
            $out[] = "{$title}:";
            array_push($out, ...$lines);
        }
        $out[] = '(class names in members are shortened; tools accept them as-is)';

        $children = (int) $this->db->scalar(
            "SELECT count(*) FROM relations WHERE target = ? COLLATE NOCASE AND kind IN ('extends', 'implements', 'uses_trait')",
            [$class['fqn']],
        );
        if ($children > 0) {
            $out[] = "direct subtypes/users: {$children} (see find_implementations)";
        }

        return implode("\n", $out);
    }

    public function fileOutline(string $path): string
    {
        $path = ltrim($path, './');
        $file = $this->db->one('SELECT id, path, parse_error FROM files WHERE path = ?', [$path])
            ?? $this->db->one("SELECT id, path, parse_error FROM files WHERE path LIKE ? ESCAPE '!' ORDER BY length(path) LIMIT 1", ['%'.$this->escapeLike($path)]);
        if (null === $file) {
            return "File \"{$path}\" is not in the index.";
        }

        $out = ["file: {$file['path']}"];
        if (null !== $file['parse_error']) {
            $out[] = 'parse error: '.$file['parse_error'];
        }
        $symbols = $this->db->all('SELECT kind, signature, start_line, end_line, parent_id FROM symbols WHERE file_id = ? ORDER BY start_line', [$file['id']]);
        foreach ($symbols as $s) {
            $indent = null === $s['parent_id'] ? '' : '  ';
            $out[] = "{$indent}{$s['signature']}  :{$s['start_line']}-{$s['end_line']}";
        }

        return implode("\n", $out);
    }

    /**
     * Everything below a type: implementations of an interface, subclasses of a class, sub-interfaces.
     */
    public function findImplementations(string $fqcn, bool $transitive = true): string
    {
        if (null !== ($ambiguous = $this->ambiguity($fqcn))) {
            return $ambiguous;
        }
        $target = $this->canonicalName($fqcn);
        $rows = $this->hierarchyDown($target, ['implements', 'extends'], $transitive);

        return $this->formatHierarchy($target, $rows, 'subtypes');
    }

    /**
     * Where a class (or one of its members) is referenced: calls, instantiations, ::class, type hints, inheritance.
     *
     * For a member, calls made through a parent class or interface (or on a subclass) are included, since they
     * can dispatch to this method, and calls on receivers of unknown type are listed separately. That way one
     * call answers "who calls X" without follow-up queries.
     */
    public function findUsages(string $fqcn, ?string $member = null, int $limit = 100): string
    {
        if (null !== ($ambiguous = $this->ambiguity($fqcn))) {
            return $ambiguous;
        }
        $limit = max(1, min($limit, 500));
        $target = $this->canonicalName($fqcn);
        $member = null === $member || '' === $member ? null : ltrim($member, ':');

        if (null === $member) {
            // Without a member, a class's references to itself ($this->foo(), self::BAR) are noise.
            $rows = $this->db->all(
                "SELECT r.kind, r.target_class, r.target_member, r.line, f.path, s.fqn AS caller
                 FROM refs r JOIN files f ON f.id = r.file_id LEFT JOIN symbols s ON s.id = r.symbol_id
                 WHERE r.target_class = :target COLLATE NOCASE
                   AND (s.fqn IS NULL OR (s.fqn <> :target COLLATE NOCASE AND s.fqn NOT LIKE :own ESCAPE '!'))
                 ORDER BY f.path, r.line LIMIT {$limit}",
                ['target' => $target, 'own' => $this->escapeLike($target).'::%'],
            );
        } else {
            $types = [$target, ...$this->ancestors($target), ...array_column($this->hierarchyDown($target, ['extends'], true), 'fqn')];
            $in = implode(',', array_map(fn (string $t) => $this->db->pdo->quote($t), $types));
            $rows = $this->db->all(
                "SELECT r.kind, r.target_class, r.target_member, r.line, f.path, s.fqn AS caller
                 FROM refs r JOIN files f ON f.id = r.file_id LEFT JOIN symbols s ON s.id = r.symbol_id
                 WHERE r.target_class COLLATE NOCASE IN ({$in}) AND r.target_member = :member
                 ORDER BY f.path, r.line LIMIT {$limit}",
                ['member' => $member],
            );
        }

        $out = [];
        if (null === $member) {
            foreach ($this->db->all(
                'SELECT r.kind, s.fqn, s.start_line, f.path FROM relations r JOIN symbols s ON s.id = r.symbol_id JOIN files f ON f.id = s.file_id
                 WHERE r.target = ? COLLATE NOCASE ORDER BY f.path',
                [$target],
            ) as $rel) {
                $out[] = "{$rel['path']}:{$rel['start_line']}  {$rel['fqn']}  [{$rel['kind']}]";
            }
        }
        foreach ($rows as $r) {
            $what = $r['kind'].(null === $r['target_member'] || null !== $member ? '' : ' '.$r['target_member']);
            if (0 !== strcasecmp($r['target_class'], $target)) {
                $what .= ' via '.$this->shortName($r['target_class']);
            }
            $out[] = "{$r['path']}:{$r['line']}  ".($r['caller'] ?? '(file scope)')."  [{$what}]";
        }

        $title = $target.(null === $member ? '' : '::'.$member);
        $result = [] === $out
            ? "No usages of {$title} found."
            : "Usages of {$title} (".\count($out).(\count($rows) === $limit ? '+, truncated' : '')."):\n".implode("\n", $out);
        if (null !== $member) {
            // The definition and any overrides are what an impact question needs next, so save it the outline call.
            $result = $this->memberDefinitions($target, $member)."\n\n".$result;
        }

        // Calls on receivers whose type couldn't be inferred might still hit this method; list them rather than hide them.
        if (null !== $member) {
            $unknown = $this->db->all(
                "SELECT r.line, f.path, s.fqn AS caller FROM refs r JOIN files f ON f.id = r.file_id LEFT JOIN symbols s ON s.id = r.symbol_id
                 WHERE r.kind = 'call' AND r.target_class IS NULL AND r.target_member = ? ORDER BY f.path, r.line",
                [$member],
            );
            if ([] !== $unknown) {
                $arrow = 'ts' === $this->lang() ? '.' : '->';
                $result .= "\n\nCalls to {$arrow}{$member}() on receivers of unknown type (may or may not be this method), ".\count($unknown).':';
                foreach (\array_slice($unknown, 0, 20) as $u) {
                    $result .= "\n{$u['path']}:{$u['line']}  ".($u['caller'] ?? '(file scope)');
                }
                if (\count($unknown) > 20) {
                    $result .= "\n… and ".(\count($unknown) - 20).' more (a common method name; narrow down with grep if it matters)';
                }
            } else {
                $result .= 'ts' === $this->lang()
                    ? "\nNo unresolved calls to .{$member}(), so this list is complete for the indexed TypeScript code."
                    : "\nNo calls on receivers of unknown type, so this list is complete for PHP code.";
            }
        }

        return $result;
    }

    /**
     * What a method or function references. Accepts "Class::method" with a short or fully-qualified class.
     */
    public function callees(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        if (str_contains($fqn, '::')) {
            [$class, $method] = explode('::', $fqn, 2);
            if (null !== ($ambiguous = $this->ambiguity($class))) {
                return $ambiguous;
            }
            $fqn = $this->canonicalName($class).'::'.$method;
        } elseif ('ts' === $this->lang()) {
            // TypeScript functions and components are top-level symbols, so short names resolve like classes.
            if (null !== ($ambiguous = $this->ambiguity($fqn))) {
                return $ambiguous;
            }
            $fqn = $this->canonicalName($fqn);
        }
        $arrow = 'ts' === $this->lang() ? '.' : '->';
        $symbol = $this->db->one(
            'SELECT s.id, s.fqn, s.start_line, f.path FROM symbols s JOIN files f ON f.id = s.file_id
             WHERE s.fqn = ? COLLATE NOCASE AND s.kind IN (\'method\', \'function\', \'component\')',
            [$fqn],
        );
        if (null === $symbol) {
            return $this->notFound($fqn);
        }

        $rows = $this->db->all('SELECT kind, target_class, target_member, line FROM refs WHERE symbol_id = ? ORDER BY line', [$symbol['id']]);
        if ([] === $rows) {
            return "{$symbol['fqn']} ({$symbol['path']}:{$symbol['start_line']}) references nothing.";
        }

        $out = ["{$symbol['fqn']} ({$symbol['path']}:{$symbol['start_line']}) references:"];
        $seen = [];
        foreach ($rows as $r) {
            $target = match ($r['kind']) {
                'call' => ($r['target_class'] ?? '?').$arrow.$r['target_member'].'()',
                'static_call' => $r['target_class'].'::'.$r['target_member'].'()',
                'new' => 'new '.$r['target_class'],
                'func' => $r['target_class'].'()',
                'const', 'static_prop' => $r['target_class'].'::'.$r['target_member'],
                'jsx' => '<'.$r['target_class'].'>',
                'prop' => $r['target_class'].'.'.$r['target_member'],
                default => $r['target_class'].' ['.$r['kind'].']',
            };
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $out[] = "  :{$r['line']}  {$target}";
        }

        return implode("\n", $out);
    }

    /**
     * Symbols carrying an attribute, e.g. "Route", "AsCommand", "Symfony\Component\Routing\Attribute\Route".
     */
    public function findByAttribute(string $name, ?string $argContains = null, int $limit = 200): string
    {
        $limit = max(1, min($limit, 1000));
        $name = ltrim($name, '\\#[');
        $params = ['name' => $name, 'suffix' => '%\\'.$this->escapeLike($name)];
        $argSql = '';
        if (null !== $argContains && '' !== $argContains) {
            $argSql = " AND a.args LIKE :arg ESCAPE '!'";
            $params['arg'] = '%'.$this->escapeLike($argContains).'%';
        }

        $rows = $this->db->all(
            "SELECT a.name, a.args, s.kind, s.fqn, s.start_line, f.path
             FROM attributes a JOIN symbols s ON s.id = a.symbol_id JOIN files f ON f.id = s.file_id
             WHERE (a.name = :name COLLATE NOCASE OR a.name LIKE :suffix ESCAPE '!'){$argSql}
             ORDER BY f.path, s.start_line LIMIT {$limit}",
            $params,
        );
        if ([] === $rows) {
            return "No symbols with attribute {$name}".(null === $argContains ? '' : " containing \"{$argContains}\"").'.';
        }

        return implode("\n", array_map(
            static fn (array $r) => "{$r['path']}:{$r['start_line']}  {$r['kind']} {$r['fqn']}  #[{$r['name']}({$r['args']})]",
            $rows,
        ));
    }

    /**
     * Namespace hierarchy with the number of classes/interfaces/traits/enums in each.
     */
    public function namespaceTree(string $prefix = '', int $depth = 3): string
    {
        $prefix = trim($prefix, '\\');
        $counts = $this->namespaceCounts($prefix);
        if ([] === $counts) {
            return '' === $prefix ? 'The index is empty.' : "No namespace under \"{$prefix}\".";
        }

        $baseDepth = '' === $prefix ? 0 : \count(explode('\\', $prefix));
        $maxParts = $baseDepth + max(1, $depth);
        // Each class counts towards every ancestor namespace, so parents show subtree totals.
        $tree = [];
        foreach ($counts as $ns => $count) {
            $parts = '' === $ns ? ['(global)'] : explode('\\', $ns);
            for ($i = max(1, $baseDepth), $n = min(\count($parts), $maxParts); $i <= $n; ++$i) {
                $key = implode('\\', \array_slice($parts, 0, $i));
                $tree[$key] = ($tree[$key] ?? 0) + $count;
            }
        }
        ksort($tree, \SORT_STRING | \SORT_FLAG_CASE);

        $out = [];
        foreach ($tree as $ns => $count) {
            $level = \count(explode('\\', $ns)) - max($baseDepth, 1);
            $out[] = str_repeat('  ', $level).$ns." ({$count})";
        }

        return implode("\n", $out);
    }

    public function indexStatus(): string
    {
        $files = (int) $this->db->scalar('SELECT count(*) FROM files');
        $symbols = $this->db->all('SELECT kind, count(*) AS n FROM symbols GROUP BY kind ORDER BY n DESC');
        $errors = $this->db->all('SELECT path, parse_error FROM files WHERE parse_error IS NOT NULL ORDER BY path LIMIT 20');
        $errorCount = (int) $this->db->scalar('SELECT count(*) FROM files WHERE parse_error IS NOT NULL');
        $time = fn (string $key) => null === ($v = $this->db->getMeta($key)) ? 'never' : gmdate('Y-m-d H:i:s', (int) $v).' UTC';

        $out = [
            "files: {$files}",
            'symbols: '.implode(', ', array_map(static fn (array $r) => "{$r['n']} {$r['kind']}", $symbols)),
            'last scan: '.$time('last_scan'),
            'last change: '.$time('last_change'),
            'last full rescan: '.$time('last_full_scan'),
            "files with parse errors: {$errorCount}",
        ];
        foreach ($errors as $e) {
            $out[] = "  {$e['path']}: {$e['parse_error']}";
        }

        return implode("\n", $out);
    }

    /**
     * @return array<string, int> namespace => number of class-like symbols
     */
    public function namespaceCounts(string $prefix = ''): array
    {
        $params = [];
        $where = "kind IN {$this->kindList()} AND parent_id IS NULL";
        if ('' !== $prefix) {
            $where .= " AND (namespace = :ns COLLATE NOCASE OR namespace LIKE :like ESCAPE '!')";
            $params = ['ns' => $prefix, 'like' => $this->escapeLike($prefix).'\\%'];
        }
        $counts = [];
        foreach ($this->db->all("SELECT namespace, count(*) AS n FROM symbols WHERE {$where} GROUP BY namespace", $params) as $row) {
            $counts[$row['namespace']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<string> $relationKinds
     *
     * @return list<array<string, mixed>>
     */
    private function hierarchyDown(string $target, array $relationKinds, bool $transitive): array
    {
        $in = implode(',', array_map(fn (string $k) => $this->db->pdo->quote($k), $relationKinds));
        $found = [];
        $queue = [$target];
        $visited = [strtolower($target) => true];

        while ([] !== $queue) {
            $current = array_shift($queue);
            $rows = $this->db->all(
                "SELECT s.kind, s.fqn, s.start_line, f.path, r.kind AS via
                 FROM relations r JOIN symbols s ON s.id = r.symbol_id JOIN files f ON f.id = s.file_id
                 WHERE r.target = ? COLLATE NOCASE AND r.kind IN ({$in})",
                [$current],
            );
            foreach ($rows as $row) {
                $key = strtolower($row['fqn']);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $row['parent'] = $current;
                $found[] = $row;
                if ($transitive) {
                    $queue[] = $row['fqn'];
                }
            }
        }

        return $found;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function formatHierarchy(string $target, array $rows, string $label): string
    {
        if ([] === $rows) {
            return "No {$label} of {$target} found in the index.";
        }

        return \count($rows)." {$label} of {$target}:\n".implode("\n", array_map(
            static fn (array $r) => "{$r['kind']} {$r['fqn']}  ({$r['path']}:{$r['start_line']})"
                .(strcasecmp($r['parent'], $target) === 0 ? '' : "  via {$r['parent']}"),
            $rows,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findClass(string $fqcn): ?array
    {
        $fqcn = ltrim($fqcn, '\\');
        $kinds = $this->kindList();
        $sql = "SELECT s.*, f.path FROM symbols s JOIN files f ON f.id = s.file_id WHERE s.kind IN {$kinds} AND s.parent_id IS NULL";

        return $this->db->one("{$sql} AND s.fqn = ? COLLATE NOCASE", [$fqcn])
            // Accept a short name when it's unambiguous, since agents often only know "UserService".
            ?? $this->uniqueRow($this->db->all("{$sql} AND s.name = ? COLLATE NOCASE LIMIT 2", [$fqcn]));
    }

    private function canonicalName(string $fqcn): string
    {
        return $this->findClass($fqcn)['fqn'] ?? ltrim($fqcn, '\\');
    }

    /**
     * A message listing the candidates when a short name matches several classes, so the agent can pick one
     * in its next call instead of silently getting results for nothing.
     */
    private function ambiguity(string $name): ?string
    {
        $name = ltrim($name, '\\');
        if (str_contains($name, '\\') || null !== $this->findClass($name)) {
            return null;
        }
        $kinds = $this->kindList();
        $rows = $this->db->all(
            "SELECT s.kind, s.fqn, s.start_line, f.path FROM symbols s JOIN files f ON f.id = s.file_id
             WHERE s.kind IN {$kinds} AND s.parent_id IS NULL AND s.name = ? COLLATE NOCASE ORDER BY s.fqn LIMIT 20",
            [$name],
        );
        if (\count($rows) < 2) {
            return null;
        }

        return "\"{$name}\" is ambiguous; repeat with one of these fully-qualified names:\n".implode("\n", array_map(
            static fn (array $r) => "{$r['kind']} {$r['fqn']}  {$r['path']}:{$r['start_line']}",
            $rows,
        ));
    }

    /**
     * Where a member is declared (on the class or the nearest ancestor that has it) and where subtypes override it.
     */
    private function memberDefinitions(string $class, string $member): string
    {
        $find = fn (string $owner) => $this->db->one(
            'SELECT s.id, s.kind, s.fqn, s.signature, s.start_line, f.path FROM symbols s JOIN files f ON f.id = s.file_id
             WHERE s.fqn COLLATE NOCASE IN (?, ?) LIMIT 1',
            [$owner.'::'.$member, $owner.'::$'.ltrim($member, '$')],
        );

        $lines = [];
        foreach ([$class, ...$this->ancestors($class)] as $owner) {
            if (null !== ($def = $find($owner))) {
                $inherited = 0 === strcasecmp($owner, $class) ? '' : '  (inherited from '.Names::short($owner).')';
                $lines[] = "Defined at {$def['path']}:{$def['start_line']}  ".Names::shortenAll($def['signature']).$inherited;
                if ('method' === $def['kind'] && '' !== ($calls = $this->outgoingCalls((int) $def['id']))) {
                    // For impact questions the next thing needed is where the method forwards to.
                    $lines[] = 'It calls: '.$calls;
                }
                break;
            }
        }
        if ([] === $lines) {
            $lines[] = "No declaration of {$member} found on {$class} or its indexed parents.";
        }

        $overrides = [];
        foreach ($this->hierarchyDown($class, ['implements', 'extends'], true) as $sub) {
            if (null !== ($def = $find($sub['fqn']))) {
                $overrides[] = "  {$def['path']}:{$def['start_line']}  ".Names::short($sub['fqn']).': '.Names::shortenAll($def['signature']);
            }
        }
        if ([] !== $overrides) {
            $lines[] = 'Also declared in subtypes ('.\count($overrides).'):';
            array_push($lines, ...\array_slice($overrides, 0, 15));
            if (\count($overrides) > 15) {
                $lines[] = '  … and '.(\count($overrides) - 15).' more (find_implementations lists all subtypes)';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Parent classes and interfaces of a type, transitively (only as far as the index knows them).
     *
     * @return list<string>
     */
    private function ancestors(string $fqcn): array
    {
        $found = [];
        $queue = [$fqcn];
        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($this->db->all(
                "SELECT r.target FROM relations r JOIN symbols s ON s.id = r.symbol_id
                 WHERE s.fqn = ? COLLATE NOCASE AND s.kind IN ('class', 'interface', 'enum') AND r.kind IN ('extends', 'implements')",
                [$current],
            ) as $row) {
                $key = strtolower($row['target']);
                if (!isset($found[$key]) && 0 !== strcasecmp($row['target'], $fqcn)) {
                    $found[$key] = $row['target'];
                    $queue[] = $row['target'];
                }
            }
        }

        return array_values($found);
    }

    /**
     * One-line summary of the calls and instantiations made by a method, e.g. "OrderService::expire() :172, new Foo :180".
     */
    private function outgoingCalls(int $symbolId, int $max = 10): string
    {
        $calls = [];
        foreach ($this->db->all(
            "SELECT kind, target_class, target_member, line FROM refs WHERE symbol_id = ? AND kind IN ('call', 'static_call', 'new', 'func', 'jsx') ORDER BY line",
            [$symbolId],
        ) as $r) {
            $label = match ($r['kind']) {
                'new' => 'new '.Names::short((string) $r['target_class']),
                'jsx' => '<'.Names::short((string) $r['target_class']).'>',
                'func' => Names::short((string) $r['target_class']).'()',
                default => (null === $r['target_class'] ? '?' : Names::short($r['target_class'])).'::'.$r['target_member'].'()',
            };
            $calls[$label] ??= $label.' :'.$r['line'];
        }
        $extra = \count($calls) > $max ? ', … '.(\count($calls) - $max).' more (see callees)' : '';

        return implode(', ', \array_slice(array_values($calls), 0, $max)).$extra;
    }

    /**
     * @param list<string> $items
     *
     * @return list<string> the items joined with ", " into lines of at most $width characters
     */
    private function wrap(array $items, int $width): array
    {
        $lines = [];
        $current = '';
        foreach ($items as $item) {
            if ('' !== $current && \strlen($current) + 2 + \strlen($item) > $width) {
                $lines[] = $current;
                $current = '';
            }
            $current .= ('' === $current ? '' : ', ').$item;
        }
        if ('' !== $current) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * "php" or "ts", as recorded by the indexer that filled the database.
     */
    public function lang(): string
    {
        return $this->db->getMeta('lang') ?? 'php';
    }

    /**
     * @return list<string> kinds of top-level symbols that behave like classes for lookups and counts
     */
    public function typeKinds(): array
    {
        return 'ts' === $this->lang() ? self::TS_TYPE_KINDS : self::CLASS_KINDS;
    }

    private function kindList(): string
    {
        return "('".implode("','", $this->typeKinds())."')";
    }

    private function shortName(string $fqcn): string
    {
        return Names::short($fqcn);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function uniqueRow(array $rows): ?array
    {
        return 1 === \count($rows) ? $rows[0] : null;
    }

    /**
     * @return list<string>
     */
    private function attributesOf(int $symbolId): array
    {
        return array_map(
            static fn (array $a) => $a['name'].('' === $a['args'] ? '' : '('.$a['args'].')'),
            $this->db->all('SELECT name, args FROM attributes WHERE symbol_id = ?', [$symbolId]),
        );
    }

    private function notFound(string $name): string
    {
        $short = basename(str_replace(['\\', '::'], '/', $name));
        $suggestions = $this->searchSymbols($short, null, 10);

        return "\"{$name}\" not found in the index.".(str_starts_with($suggestions, 'No symbols') ? '' : "\nDid you mean:\n{$suggestions}");
    }

    private function ftsQuery(string $query, string $operator): string
    {
        // Treat the user's text as plain terms; FTS operators in free text cause syntax errors.
        $terms = preg_split('/[^\p{L}\p{N}]+/u', Indexer::splitWords($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode($operator, array_map(static fn (string $t) => '"'.$t.'"*', $terms));
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
