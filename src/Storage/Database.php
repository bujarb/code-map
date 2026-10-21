<?php

declare(strict_types=1);

namespace CodeMap\Storage;

use PDO;

final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if (':memory:' !== $path && !is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }

        $this->pdo = new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // WAL lets the MCP server read while the watcher writes; busy_timeout covers the brief checkpoint locks.
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec((string) file_get_contents(__DIR__.'/schema.sql'));
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    public function getMeta(string $key): ?string
    {
        $value = $this->scalar('SELECT value FROM meta WHERE key = ?', [$key]);

        return false === $value ? null : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO meta(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }
}
