<?php

declare(strict_types=1);

namespace CodeMap\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Runtime configuration. Precedence: built-in defaults < codemap.yaml in the project root < CODEMAP_* env vars.
 */
final readonly class Config
{
    public const array DEFAULT_EXCLUDES = [
        'vendor',
        'node_modules',
        'var',
        'cache',
        '.git',
        '.idea',
        '.claude',
        '.code-map',
    ];

    /**
     * Added to DEFAULT_EXCLUDES in TypeScript mode: build output, caches and package-manager stores.
     */
    public const array TS_EXCLUDES = [
        'dist',
        'build',
        '.next',
        'coverage',
        '.nx',
        '.turbo',
        '.cache',
        '.pnpm-store',
        '.yarn',
    ];

    public const array LANGUAGES = ['php', 'ts'];

    /**
     * @param list<string> $include  directories (relative to the project root) to scan
     * @param list<string> $exclude  directory names, or relative path globs when they contain a "/"
     * @param list<string> $allowedHosts Host headers the MCP HTTP endpoint accepts
     * @param string       $lang         "php" or "ts": which parser indexes the project
     */
    public function __construct(
        public string $projectRoot,
        public string $databasePath,
        public ?string $outputDir,
        public array $include = ['.'],
        public array $exclude = self::DEFAULT_EXCLUDES,
        public int $pollInterval = 5,
        public int $fullRescanInterval = 86400,
        public int $overviewMaxChars = 24000,
        public string $httpHost = '0.0.0.0',
        public int $httpPort = 8765,
        public array $allowedHosts = ['localhost', '127.0.0.1', '[::1]'],
        public string $lang = 'php',
    ) {
        if (!\in_array($lang, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException("Unsupported CODEMAP_LANG \"{$lang}\"; use php, ts or auto.");
        }
    }

    /**
     * @return list<string> file extensions (without dot) the indexer picks up
     */
    public function extensions(): array
    {
        return 'ts' === $this->lang ? ['ts', 'tsx', 'mts', 'cts'] : ['php'];
    }

    /**
     * @return list<string>
     */
    public static function defaultExcludes(string $lang): array
    {
        return 'ts' === $lang ? [...self::DEFAULT_EXCLUDES, ...self::TS_EXCLUDES] : self::DEFAULT_EXCLUDES;
    }

    /**
     * "auto" picks PHP when the root has a composer.json, TypeScript when it has a tsconfig.json or package.json.
     */
    public static function detectLanguage(string $root): string
    {
        if (is_file($root.'/composer.json')) {
            return 'php';
        }

        return is_file($root.'/tsconfig.json') || is_file($root.'/package.json') ? 'ts' : 'php';
    }

    /**
     * @param array<string, string> $env
     */
    public static function load(array $env = []): self
    {
        $env = $env ?: getenv();

        $root = rtrim($env['CODEMAP_PROJECT'] ?? '/project', '/');
        $file = [];
        $configFile = $env['CODEMAP_CONFIG'] ?? $root.'/codemap.yaml';
        if (is_file($configFile)) {
            $file = Yaml::parseFile($configFile) ?? [];
        }

        $list = static fn (?string $value): ?array => null === $value || '' === trim($value)
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v) => '' !== $v));

        $lang = strtolower(trim((string) ($env['CODEMAP_LANG'] ?? $file['lang'] ?? 'php'))) ?: 'php';
        if ('auto' === $lang) {
            $lang = self::detectLanguage($root);
        }

        $include = $list($env['CODEMAP_INCLUDE'] ?? null) ?? $file['include'] ?? ['.'];
        // User excludes extend the defaults rather than replace them; replacing would make it easy to index vendor/ by accident.
        $exclude = array_values(array_unique([
            ...self::defaultExcludes($lang),
            ...($file['exclude'] ?? []),
            ...($list($env['CODEMAP_EXCLUDE'] ?? null) ?? []),
        ]));

        $output = $env['CODEMAP_OUT'] ?? $file['output'] ?? '/out';

        return new self(
            projectRoot: $root,
            databasePath: $env['CODEMAP_DB'] ?? $file['database'] ?? '/data/index.sqlite',
            outputDir: '' === $output ? null : rtrim($output, '/'),
            include: $include,
            exclude: $exclude,
            pollInterval: (int) ($env['CODEMAP_POLL_INTERVAL'] ?? $file['poll_interval'] ?? 5),
            fullRescanInterval: (int) ($env['CODEMAP_FULL_RESCAN_INTERVAL'] ?? $file['full_rescan_interval'] ?? 86400),
            overviewMaxChars: (int) ($env['CODEMAP_OVERVIEW_MAX_CHARS'] ?? $file['overview_max_chars'] ?? 24000),
            httpHost: $env['CODEMAP_HTTP_HOST'] ?? '0.0.0.0',
            httpPort: (int) ($env['CODEMAP_HTTP_PORT'] ?? 8765),
            allowedHosts: $list($env['CODEMAP_ALLOWED_HOSTS'] ?? null) ?? ['localhost', '127.0.0.1', '[::1]'],
            lang: $lang,
        );
    }
}
