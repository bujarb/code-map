<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

/**
 * Everything extracted from one file, before it is written to the database.
 * Symbols reference each other by their position in $symbols ("local id").
 */
final class FileSymbols
{
    /**
     * @var list<array{kind: string, fqn: string, name: string, namespace: string, signature: string, visibility: ?string, flags: string, doc: ?string, start_line: int, end_line: int, parent: ?int}>
     */
    public array $symbols = [];

    /** @var list<array{symbol: int, kind: string, target: string}> */
    public array $relations = [];

    /** @var list<array{symbol: ?int, kind: string, target_class: ?string, target_member: ?string, line: int}> */
    public array $refs = [];

    /** @var list<array{symbol: int, name: string, args: string}> */
    public array $attributes = [];

    /**
     * @param array{kind: string, fqn: string, name: string, namespace: string, signature: string, visibility: ?string, flags: string, doc: ?string, start_line: int, end_line: int, parent: ?int} $symbol
     */
    public function addSymbol(array $symbol): int
    {
        $this->symbols[] = $symbol;

        return \count($this->symbols) - 1;
    }
}
