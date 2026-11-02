<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

/**
 * Turns source files into FileSymbols. One implementation per language; the Indexer does change detection and storage.
 */
interface SourceParser
{
    /**
     * Called at the start of every sync with every file currently on disk, before any parseFiles() call.
     * Parsers that resolve names across files (TypeScript) use it to keep their program in sync.
     *
     * @param list<string> $files paths relative to the project root
     */
    public function beginSync(array $files): void;

    /**
     * @param array<string, string> $sources relative path => file content
     *
     * @return array<string, array{0: FileSymbols, 1: ?string}> relative path => [symbols, first parse error]
     */
    public function parseFiles(array $sources): array;
}
