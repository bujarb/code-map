<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

use CodeMap\Config\Config;
use CodeMap\Storage\Database;
use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Brings the database in line with the files on disk. Only files whose mtime/size changed are read,
 * and only files whose content hash changed are re-parsed.
 */
final class Indexer
{
    private const int BATCH_SIZE = 250;

    private readonly Parser $parser;
    private readonly FileFinder $finder;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new FileFinder($config);
    }

    /**
     * @param bool $full re-hash every file instead of trusting mtime/size
     */
    public function sync(bool $full = false, ?callable $progress = null): SyncResult
    {
        $started = microtime(true);
        $onDisk = $this->finder->find();

        $known = [];
        foreach ($this->db->all('SELECT id, path, hash, mtime, size FROM files') as $row) {
            $known[$row['path']] = $row;
        }

        $candidates = [];
        foreach ($onDisk as $path => $stat) {
            $row = $known[$path] ?? null;
            if ($full || null === $row || (int) $row['mtime'] !== $stat['mtime'] || (int) $row['size'] !== $stat['size']) {
                $candidates[$path] = $stat;
            }
        }
        $removed = array_diff_key($known, $onDisk);

        $result = new SyncResult(scanned: \count($onDisk));
        $pdo = $this->db->pdo;
        $done = 0;

        foreach (array_chunk($candidates, self::BATCH_SIZE, true) as $batch) {
            $pdo->beginTransaction();
            try {
                foreach ($batch as $path => $stat) {
                    $this->indexFile($path, $stat, $known[$path] ?? null, $result);
                    ++$done;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            if (null !== $progress) {
                $progress($done, \count($candidates));
            }
        }

        if ([] !== $removed) {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM files WHERE id = ?');
            foreach ($removed as $row) {
                $delete->execute([$row['id']]);
            }
            $pdo->commit();
            $result->removed = array_keys($removed);
        }

        $result->duration = microtime(true) - $started;
        $this->db->setMeta('last_scan', (string) time());
        if ($full) {
            $this->db->setMeta('last_full_scan', (string) time());
        }
        if ($result->changed()) {
            $this->db->setMeta('last_change', (string) time());
        }

        return $result;
    }

    /**
     * @param array{mtime: int, size: int}  $stat
     * @param array<string, mixed>|null      $existing
     */
    private function indexFile(string $path, array $stat, ?array $existing, SyncResult $result): void
    {
        $code = @file_get_contents($this->config->projectRoot.'/'.$path);
        if (false === $code) {
            return; // vanished between stat and read; the next poll will remove it
        }
        $hash = sha1($code);

        if (null !== $existing && $existing['hash'] === $hash) {
            $this->db->pdo->prepare('UPDATE files SET mtime = ?, size = ? WHERE id = ?')
                ->execute([$stat['mtime'], $stat['size'], $existing['id']]);

            return;
        }

        [$symbols, $error] = $this->parse($code);

        if (null !== $existing) {
            $this->db->pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$existing['id']]);
        }
        $this->db->pdo->prepare('INSERT INTO files(path, hash, mtime, size, parse_error, indexed_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$path, $hash, $stat['mtime'], $stat['size'], $error, time()]);
        $fileId = (int) $this->db->pdo->lastInsertId();

        $this->store($fileId, $symbols);

        $result->indexed[] = $path;
        if (null !== $error) {
            $result->errors[$path] = $error;
        }
    }

    /**
     * @return array{0: FileSymbols, 1: ?string}
     */
    public function parse(string $code): array
    {
        // Collect errors instead of throwing so a half-written file still yields whatever parsed cleanly.
        $errors = new Collecting();
        try {
            $ast = $this->parser->parse($code, $errors) ?? [];
        } catch (Error $e) {
            return [new FileSymbols(), $e->getMessage()];
        }

        // Two passes: the collector looks ahead at property/constructor types when entering a class,
        // so every name in the file must already be resolved by then.
        $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
        $collector = new SymbolCollector();
        (new NodeTraverser($collector))->traverse($ast);

        $error = $errors->hasErrors() ? $errors->getErrors()[0]->getMessage() : null;

        return [$collector->result, $error];
    }

    public static function splitWords(string $name): string
    {
        $words = preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/_+/'], ['$1 $2', '$1 $2', ' '], $name);

        return strtolower(trim((string) $words));
    }

    private function store(int $fileId, FileSymbols $symbols): void
    {
        $pdo = $this->db->pdo;
        $insertSymbol = $pdo->prepare(
            'INSERT INTO symbols(file_id, parent_id, kind, fqn, name, namespace, signature, visibility, flags, doc, start_line, end_line)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );

        $insertFts = $pdo->prepare('INSERT INTO symbols_fts(rowid, fqn, words, doc) VALUES (?, ?, ?, ?)');

        $ids = [];
        foreach ($symbols->symbols as $local => $s) {
            $insertSymbol->execute([
                $fileId,
                null === $s['parent'] ? null : $ids[$s['parent']],
                $s['kind'], $s['fqn'], $s['name'], $s['namespace'], $s['signature'],
                $s['visibility'], $s['flags'], $s['doc'], $s['start_line'], $s['end_line'],
            ]);
            $ids[$local] = (int) $pdo->lastInsertId();
            $insertFts->execute([$ids[$local], $s['fqn'], self::splitWords($s['name']), $s['doc']]);
        }

        $insertRelation = $pdo->prepare('INSERT INTO relations(symbol_id, kind, target) VALUES (?, ?, ?)');
        foreach ($symbols->relations as $r) {
            $insertRelation->execute([$ids[$r['symbol']], $r['kind'], $r['target']]);
        }

        $insertRef = $pdo->prepare('INSERT INTO refs(file_id, symbol_id, kind, target_class, target_member, line) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($symbols->refs as $r) {
            $insertRef->execute([$fileId, null === $r['symbol'] ? null : $ids[$r['symbol']], $r['kind'], $r['target_class'], $r['target_member'], $r['line']]);
        }

        $insertAttribute = $pdo->prepare('INSERT INTO attributes(symbol_id, name, args) VALUES (?, ?, ?)');
        foreach ($symbols->attributes as $a) {
            $insertAttribute->execute([$ids[$a['symbol']], $a['name'], $a['args']]);
        }
    }
}
