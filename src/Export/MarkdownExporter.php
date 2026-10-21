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
        if (!is_dir($dir.'/namespaces')) {
            mkdir($dir.'/namespaces', 0777, true);
        }

        $written = 0;
        $expected = [];
        foreach ($this->namespaceDocs() as $namespace => $content) {
            $file = 'namespaces/'.$this->fileName($namespace);
            $expected[$file] = true;
            $written += $this->write($dir.'/'.$file, $content);
        }
        foreach (glob($dir.'/namespaces/*.md') ?: [] as $file) {
            if (!isset($expected['namespaces/'.basename($file)])) {
                unlink($file);
            }
        }

        $written += $this->write($dir.'/OVERVIEW.md', $this->overview($projectName));

        return $written;
    }

    public function overview(string $projectName): string
    {
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

    /**
     * @return iterable<string, string> namespace => markdown
     */
    private function namespaceDocs(): iterable
    {
        $classes = $this->db->all(
            "SELECT s.id, s.kind, s.fqn, s.name, s.namespace, s.signature, s.doc, s.flags, s.start_line, f.path
             FROM symbols s JOIN files f ON f.id = s.file_id
             WHERE s.kind IN ('class','interface','trait','enum')
             ORDER BY s.namespace, s.name",
        );
        if ([] === $classes) {
            return;
        }

        $members = [];
        foreach ($this->db->all(
            "SELECT m.parent_id, m.kind, m.name, m.signature, m.doc FROM symbols m
             WHERE m.parent_id IS NOT NULL AND (m.kind IN ('method', 'case') AND (m.visibility = 'public' OR m.name = '__construct'))
             ORDER BY m.parent_id, m.start_line",
        ) as $m) {
            $members[$m['parent_id']][] = $m;
        }

        $byNamespace = [];
        foreach ($classes as $c) {
            $byNamespace[$c['namespace']][] = $c;
        }

        foreach ($byNamespace as $namespace => $list) {
            $lines = ['# '.('' === $namespace ? '(global namespace)' : $namespace), ''];
            foreach ($list as $c) {
                $lines[] = "## {$c['name']}";
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
