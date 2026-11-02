<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

use CodeMap\Config\Config;

/**
 * Parses TypeScript/TSX through a long-running Node process (ts/src/indexer.mjs) that holds one TypeScript
 * LanguageService per tsconfig.json. Keeping it alive between polls means a change re-checks only what changed.
 *
 * Protocol: one JSON request per line on the child's stdin, one JSON response per line on its stdout.
 */
final class TsSourceParser implements SourceParser
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var list<string> */
    private array $files = [];

    private bool $filesSent = false;

    public function __construct(
        private readonly Config $config,
        private readonly ?string $script = null,
    ) {
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function beginSync(array $files): void
    {
        if ($files !== $this->files) {
            $this->files = $files;
            $this->filesSent = false;
        }
    }

    public function version(): string
    {
        $script = $this->scriptPath();
        $hash = is_file($script) ? sha1_file($script) : false;

        return false === $hash ? '' : 'ts:'.$hash;
    }

    public function parseFiles(array $sources): array
    {
        if ([] === $sources) {
            return [];
        }

        $request = ['parse' => array_map('strval', array_keys($sources))];
        if (!$this->filesSent || !$this->running()) {
            $request['files'] = $this->files;
        }
        $response = $this->call($request);
        $this->filesSent = true;

        $results = [];
        foreach ($sources as $path => $_) {
            $row = $response['results'][$path] ?? null;
            if (!\is_array($row)) {
                $results[$path] = [new FileSymbols(), 'TypeScript indexer returned no result'];
                continue;
            }
            $symbols = new FileSymbols();
            $symbols->symbols = $row['symbols'] ?? [];
            $symbols->relations = $row['relations'] ?? [];
            $symbols->refs = $row['refs'] ?? [];
            $symbols->attributes = $row['attributes'] ?? [];
            $results[$path] = [$symbols, $row['error'] ?? null];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function call(array $request): array
    {
        if (!$this->running()) {
            $this->start();
            $request['files'] = $this->files;
        }

        $json = json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
        if (false === @fwrite($this->pipes[0], $json) || !@fflush($this->pipes[0])) {
            $this->stop();
            throw new \RuntimeException('Could not write to the TypeScript indexer process');
        }

        $line = fgets($this->pipes[1]);
        if (false === $line) {
            $this->stop();
            throw new \RuntimeException('The TypeScript indexer process exited unexpectedly (see its stderr above)');
        }
        $response = json_decode($line, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_array($response)) {
            throw new \RuntimeException('Unexpected response from the TypeScript indexer');
        }
        if (isset($response['error'])) {
            throw new \RuntimeException('TypeScript indexer: '.$response['error']);
        }

        return $response;
    }

    private function running(): bool
    {
        return \is_resource($this->process) && (proc_get_status($this->process)['running'] ?? false);
    }

    private function scriptPath(): string
    {
        return $this->script ?? \dirname(__DIR__, 2).'/ts/src/indexer.mjs';
    }

    private function start(): void
    {
        $this->stop();
        $script = $this->scriptPath();
        if (!is_file($script)) {
            throw new \RuntimeException("TypeScript indexer not found at {$script}");
        }
        $node = getenv('CODEMAP_NODE') ?: 'node';
        $memory = (int) (getenv('CODEMAP_NODE_MEMORY_MB') ?: 4096);
        $stderr = \defined('STDERR') ? \STDERR : ['file', '/dev/null', 'w'];

        $process = proc_open(
            [$node, '--max-old-space-size='.$memory, $script, $this->config->projectRoot],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException("Could not start the TypeScript indexer ({$node} {$script})");
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->filesSent = false;
    }

    private function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }
}
