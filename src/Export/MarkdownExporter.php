<?php

declare(strict_types=1);

namespace CodeMap\Export;

use CodeMap\Query\CodeQuery;
use CodeMap\Query\Names;
use CodeMap\Storage\Database;

/**
 * Writes a token-budgeted OVERVIEW.md plus one file per namespace, for agents to read without the MCP server.
 */
final readonly class MarkdownExporter
{
    public function __construct(
        private Database $db,
        private CodeQuery $query,
        private int $overviewMaxChars,
    ) {
    }

    /**
     * @return int number of files written (unchanged files are not rewritten)
     */
    public function export(string $dir, string $projectName): int
    {
        // PHP gets one file per namespace; TypeScript one per module directory.
        $sub = $this->isTs() ? 'modules' : 'namespaces';
        if (!is_dir($dir.'/'.$sub)) {
            mkdir($dir.'/'.$sub, 0777, true);
        }

        $written = 0;
        $expected = [];
        foreach ($this->namespaceDocs() as $namespace => $content) {
            $file = $sub.'/'.$this->fileName((string) $namespace);
            $expected[$file] = true;
            $written += $this->write($dir.'/'.$file, $content);
        }
        foreach (glob($dir.'/'.$sub.'/*.md') ?: [] as $file) {
            if (!isset($expected[$sub.'/'.basename($file)])) {
                unlink($file);
            }
        }

        $written += $this->write($dir.'/OVERVIEW.md', $this->overview($projectName));

        return $written;
    }

    public function overview(string $projectName): string
    {
        if ($this->isTs()) {
            return $this->tsOverview($projectName);
        }
        $files = (int) $this->db->scalar('SELECT count(*) FROM files');
        $kinds = $this->db->all("SELECT kind, count(*) AS n FROM symbols WHERE kind IN ('class','interface','trait','enum','function') GROUP BY kind ORDER BY n DESC");

        $head = [
            "# Code map: {$projectName}",
            '',
            'Generated from the PHP AST by php-code-map. Do not edit; it is regenerated when code changes.',
            '',
            "{$files} files · ".implode(' · ', array_map(static fn (array $r) => "{$r['kind']}: {$r['n']}", $kinds)),
            '',
            '## How to use',
            '',
            '- Namespace details (classes, parents, public method signatures): `namespaces/<Namespace.With.Dots>.md`.',
            '- For precise questions use the `code-map` MCP tools: `find_usages`, `class_outline`, `find_implementations`,',
            '  `callees`, `find_by_attribute`, `search_symbols`, `namespace_tree`. Short class names work.',
            '',
        ];

        $attributes = $this->db->all('SELECT name, count(*) AS n FROM attributes GROUP BY name ORDER BY n DESC LIMIT 25');
        $attrSection = [];
        if ([] !== $attributes) {
            $attrSection = ['## Most used attributes', '', 'Use `find_by_attribute` to list them (routes, commands, message handlers, entities, ...).', ''];
            foreach ($attributes as $a) {
                $attrSection[] = "- `{$a['name']}`: {$a['n']}";
            }
            $attrSection[] = '';
        }

        $fixed = implode("\n", [...$head, ...$attrSection]);
        $budget = $this->overviewMaxChars - \strlen($fixed) - 200;

        // Use the deepest namespace tree that still fits the budget.
        $tree = '';
        for ($depth = 8; $depth >= 1; --$depth) {
            $tree = $this->query->namespaceTree('', $depth);
            if (\strlen($tree) <= $budget) {
                break;
            }
        }
        if (\strlen($tree) > $budget) {
            $tree = substr($tree, 0, max(0, $budget))."\n… (truncated, use namespace_tree)";
        }

        return implode("\n", [...$head, '## Namespaces (class count)', '', '```', $tree, '```', '', ...$attrSection]);
    }

    private function tsOverview(string $projectName): string
    {
        $files = (int) $this->db->scalar('SELECT count(*) FROM files');
        $kinds = $this->db->all('SELECT kind, count(*) AS n FROM symbols WHERE parent_id IS NULL GROUP BY kind ORDER BY n DESC');
        $errors = (int) $this->db->scalar('SELECT count(*) FROM files WHERE parse_error IS NOT NULL');

        $head = [
            "# Code map: {$projectName}",
            '',
            'Generated from the TypeScript AST by code-map. Do not edit; it is regenerated when code changes.',
            '',
            "{$files} files · ".implode(' · ', array_map(static fn (array $r) => "{$r['kind']}: {$r['n']}", $kinds))
                .($errors > 0 ? " · files with parse errors: {$errors}" : ''),
            '',
            '## How to use',
            '',
            '- Module details (exports, components, interfaces with their members): `modules/<dir.with.dots>.md`,',
            '  e.g. `packages/shared/utils` → `modules/packages.shared.utils.md`.',
            '- Symbols are named `<dir>\\<file>\\<Name>` (the file part is left out for `index.ts` and when it equals the name).',
            '  Members are `Name::member`. Short names work in every tool.',
            '- For precise questions use the `code-map` MCP tools: `find_usages` (imports, JSX usages, calls, type references),',
            '  `class_outline`, `find_implementations`, `callees`, `find_by_attribute` (decorators), `search_symbols`, `namespace_tree`.',
            '',
        ];

        $attributes = $this->db->all('SELECT name, count(*) AS n FROM attributes GROUP BY name ORDER BY n DESC LIMIT 25');
        $attrSection = [];
        if ([] !== $attributes) {
            $attrSection = ['## Most used decorators', '', 'Use `find_by_attribute` to list them.', ''];
            foreach ($attributes as $a) {
                $attrSection[] = "- `@{$a['name']}`: {$a['n']}";
            }
            $attrSection[] = '';
        }

        $fixed = implode("\n", [...$head, ...$attrSection]);
        $budget = $this->overviewMaxChars - \strlen($fixed) - 200;
        $tree = '';
        for ($depth = 8; $depth >= 1; --$depth) {
            $tree = str_replace('\\', '/', $this->query->namespaceTree('', $depth));
            if (\strlen($tree) <= $budget) {
                break;
            }
        }
        if (\strlen($tree) > $budget) {
            $tree = substr($tree, 0, max(0, $budget))."\n… (truncated, use namespace_tree)";
        }

        return implode("\n", [...$head, '## Modules (top-level symbol count)', '', '```', $tree, '```', '', ...$attrSection]);
    }

    private function isTs(): bool
    {
        return 'ts' === $this->query->lang();
    }

    /**
     * @return iterable<string, string> namespace => markdown
     */
    private function namespaceDocs(): iterable
    {
        $ts = $this->isTs();
        $kinds = "('".implode("','", $this->query->typeKinds())."')";
        $classes = $this->db->all(
            "SELECT s.id, s.kind, s.fqn, s.name, s.namespace, s.signature, s.doc, s.flags, s.start_line, f.path
             FROM symbols s JOIN files f ON f.id = s.file_id
             WHERE s.kind IN {$kinds} AND s.parent_id IS NULL
             ORDER BY s.namespace, ".($ts ? 'f.path, s.start_line' : 's.name'),
        );
        if ([] === $classes) {
            return;
        }

        // TypeScript interfaces and object types are mostly data shapes (props), so their properties are listed too.
        $memberKinds = $ts ? "('method', 'case', 'property')" : "('method', 'case')";
        $members = [];
        foreach ($this->db->all(
            "SELECT m.parent_id, m.kind, m.name, m.signature, m.doc FROM symbols m
             WHERE m.parent_id IS NOT NULL AND (m.kind IN {$memberKinds} AND (m.visibility = 'public' OR m.name = '__construct'))
             ORDER BY m.parent_id, m.start_line",
        ) as $m) {
            $members[$m['parent_id']][] = $m;
        }

        $byNamespace = [];
        foreach ($classes as $c) {
            $byNamespace[$c['namespace']][] = $c;
        }

        foreach ($byNamespace as $namespace => $list) {
            $title = $ts ? ('' === $namespace ? '(project root)' : str_replace('\\', '/', $namespace)) : ('' === $namespace ? '(global namespace)' : $namespace);
            $lines = ['# '.$title, ''];
            foreach ($list as $c) {
                $lines[] = $ts ? "## {$c['name']} ({$c['kind']})" : "## {$c['name']}";
                $lines[] = '';
                $lines[] = '`'.$this->shorten($c['signature'])."` — `{$c['path']}:{$c['start_line']}`";
                if (null !== $c['doc']) {
                    $lines[] = '';
                    $lines[] = '> '.$c['doc'];
                }
                $memberLines = [];
                $cases = [];
                foreach ($members[$c['id']] ?? [] as $m) {
                    if ('case' === $m['kind']) {
                        $cases[] = $m['name'];
                        continue;
                    }
                    $memberLines[] = '- `'.$this->shorten(preg_replace('/^public /', '', $m['signature']) ?? '').'`'
                        .(null === $m['doc'] ? '' : ' — '.$m['doc']);
                }
                if ([] !== $cases) {
                    $lines[] = '';
                    $lines[] = 'cases: '.implode(', ', $cases);
                }
                if ([] !== $memberLines) {
                    $lines[] = '';
                    array_push($lines, ...$memberLines);
                }
                $lines[] = '';
            }

            yield $namespace => implode("\n", $lines);
        }
    }

    private function shorten(string $signature): string
    {
        return Names::shortenAll($signature);
    }

    private function fileName(string $namespace): string
    {
        return ('' === $namespace ? '_global' : str_replace('\\', '.', $namespace)).'.md';
    }

    private function write(string $path, string $content): int
    {
        if (is_file($path) && file_get_contents($path) === $content) {
            return 0;
        }
        // Write-then-rename so a reader never sees a half-written file.
        $tmp = $path.'.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);

        return 1;
    }
}
