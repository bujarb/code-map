<?php

declare(strict_types=1);

namespace CodeMap\Tests;

/**
 * Talks real MCP JSON-RPC to `codemap serve --stdio` to make sure the SDK wiring and tool schemas work.
 */
final class McpServerTest extends IndexTestCase
{
    public function testListAndCallToolsOverStdio(): void
    {
        $process = proc_open(
            [\PHP_BINARY, \dirname(__DIR__).'/bin/codemap', 'serve', '--stdio'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['CODEMAP_PROJECT' => $this->root, 'CODEMAP_DB' => $this->config->databasePath, 'CODEMAP_OUT' => ''] + getenv(),
        );
        self::assertIsResource($process);

        try {
            $send = static function (array $message) use ($pipes): void {
                fwrite($pipes[0], json_encode($message)."\n");
                fflush($pipes[0]);
            };
            $receive = static function () use ($pipes): array {
                $line = fgets($pipes[1]);
                self::assertNotFalse($line, 'server closed stdout');

                return json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            };

            $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]]);
            $init = $receive();
            self::assertSame('php-code-map', $init['result']['serverInfo']['name']);
            $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            $tools = array_column($receive()['result']['tools'], null, 'name');
            self::assertCount(8, $tools, 'every tool definition costs context in every session; keep the set small');
            self::assertArrayHasKey('find_usages', $tools);
            self::assertSame(['fqcn'], $tools['find_usages']['inputSchema']['required']);
            self::assertTrue($tools['find_usages']['_meta']['anthropic/alwaysLoad'], 'skips Claude Code\'s ToolSearch round trip');

            $send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'find_implementations',
                'arguments' => ['fqcn' => 'Shop\Domain\OrderRepository'],
            ]]);
            $call = $receive();
            self::assertStringContainsString('InMemoryOrderRepository', $call['result']['content'][0]['text']);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
        }
    }
}
