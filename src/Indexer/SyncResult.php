<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

final class SyncResult
{
    /** @var list<string> */
    public array $indexed = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var array<string, string> */
    public array $errors = [];

    public float $duration = 0.0;

    public function __construct(public readonly int $scanned)
    {
    }

    public function changed(): bool
    {
        return [] !== $this->indexed || [] !== $this->removed;
    }

    public function summary(): string
    {
        return \sprintf(
            '%d files scanned, %d (re)indexed, %d removed, %d with parse errors in %.2fs',
            $this->scanned,
            \count($this->indexed),
            \count($this->removed),
            \count($this->errors),
            $this->duration,
        );
    }
}
