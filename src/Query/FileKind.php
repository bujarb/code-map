<?php

declare(strict_types=1);

namespace CodeMap\Query;

/**
 * Classifies indexed files by path into production code ("src"), tests, stories and generated code.
 *
 * All of them stay indexed; queries list production code first and can leave the rest out
 * (exclude="tests,stories,generated"). The classification is computed from the path at query time,
 * so it needs no schema change and applies to existing indexes.
 */
final class FileKind
{
    public const string SRC = 'src';
    public const string TEST = 'test';
    public const string STORY = 'story';
    public const string GENERATED = 'generated';

    /** Checked in this order; GLOB patterns (case-sensitive) matched against "/<relative path>". */
    private const array PATTERNS = [
        self::GENERATED => ['*/generated/*', '*/__generated__/*', '*.generated.*', '*.gen.*'],
        self::STORY => ['*.stories.*', '*.story.*', '*/.storybook*', '*/storybook/*', '*/stories/*'],
        self::TEST => [
            '*.test.*', '*.spec.*', '*/__tests__/*', '*/__mocks__/*', '*/tests/*', '*/test/*', '*/e2e/*',
            '*/test-utils/*', '*/testing/*', '*/fixtures/*', '*Test.php', '*/setupTests.*', '*/vitest.setup.*',
        ],
    ];

    /** Sort order: production code first, generated code last. */
    private const array RANK = [self::SRC => 0, self::TEST => 1, self::STORY => 2, self::GENERATED => 3];

    private const array ALIASES = [
        'test' => self::TEST, 'tests' => self::TEST, 'spec' => self::TEST, 'specs' => self::TEST,
        'story' => self::STORY, 'stories' => self::STORY, 'storybook' => self::STORY,
        'generated' => self::GENERATED, 'gen' => self::GENERATED,
    ];

    public static function of(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        foreach (self::PATTERNS as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $path)) {
                    return $kind;
                }
            }
        }

        return self::SRC;
    }

    public static function rank(string $kind): int
    {
        return self::RANK[$kind] ?? 0;
    }

    /**
     * SQL expression evaluating to the kind of the file whose relative path is in $column.
     */
    public static function sql(string $column): string
    {
        $cases = [];
        foreach (self::PATTERNS as $kind => $patterns) {
            $conditions = array_map(static fn (string $p) => "('/' || {$column}) GLOB '{$p}'", $patterns);
            $cases[] = 'WHEN '.implode(' OR ', $conditions)." THEN '{$kind}'";
        }

        return 'CASE '.implode(' ', $cases)." ELSE 'src' END";
    }

    /**
     * SQL expression for the sort rank (0 = production code).
     */
    public static function rankSql(string $column): string
    {
        $kind = self::sql($column);

        return "(CASE {$kind} WHEN 'src' THEN 0 WHEN 'test' THEN 1 WHEN 'story' THEN 2 ELSE 3 END)";
    }

    /**
     * @return list<string> kinds named in a comma-separated exclude list such as "tests,stories,generated"
     */
    public static function parseExclude(?string $exclude): array
    {
        $kinds = [];
        foreach (preg_split('/[\s,]+/', strtolower((string) $exclude), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!isset(self::ALIASES[$word])) {
                throw new \InvalidArgumentException("Unknown exclude value \"{$word}\"; use tests, stories and/or generated.");
            }
            $kinds[self::ALIASES[$word]] = true;
        }

        return array_keys($kinds);
    }

    /**
     * @param list<string> $kinds
     */
    public static function describe(array $kinds): string
    {
        return implode('/', array_map(static fn (string $k) => match ($k) {
            self::TEST => 'tests',
            self::STORY => 'stories',
            default => $k,
        }, $kinds));
    }
}
