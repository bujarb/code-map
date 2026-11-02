<?php

declare(strict_types=1);

namespace CodeMap\Tests;

use CodeMap\Config\Config;
use CodeMap\Export\MarkdownExporter;

/**
 * Indexes tests/fixtures/ts-project (a tiny React + TypeScript monorepo with tsconfig paths) through the Node extractor.
 */
final class TypeScriptTest extends IndexTestCase
{
    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $out, $code);
        if (0 !== $code || !is_dir(\dirname(__DIR__).'/ts/node_modules/typescript')) {
            self::markTestSkipped('needs node and `npm ci --prefix ts` (the Docker test image has both)');
        }
        parent::setUp();
    }

    protected function fixture(): string
    {
        return 'ts-project';
    }

    protected function lang(): string
    {
        return 'ts';
    }

    public function testExtractsDeclarationsWithDirectoryBasedNames(): void
    {
        $symbols = array_column($this->db->all('SELECT fqn, kind FROM symbols'), 'kind', 'fqn');

        self::assertSame('component', $symbols['packages\shared\components\Button']);
        self::assertSame('component', $symbols['packages\shared\components\Button\IconButton'], 'memo(...) wrapped arrow component');
        self::assertSame('interface', $symbols['packages\shared\components\Button\ButtonProps']);
        self::assertSame('property', $symbols['packages\shared\components\Button\ButtonProps::label']);
        self::assertSame('class', $symbols['packages\shared\domain\Order']);
        self::assertSame('interface', $symbols['packages\shared\domain\Order\OrderRepository']);
        self::assertSame('enum', $symbols['packages\shared\domain\Order\OrderStatus']);
        self::assertSame('case', $symbols['packages\shared\domain\Order\OrderStatus::Cancelled']);
        self::assertSame('type', $symbols['packages\shared\domain\Order\OrderId']);
        self::assertSame('function', $symbols['packages\shared\domain\Order\formatOrderId']);
        self::assertSame('function', $symbols['apps\web\hooks\useCart']);
        self::assertSame('component', $symbols['apps\web\pages\CheckoutPage'], 'default-exported function component');
        self::assertSame('method', $symbols['packages\shared\domain\Order::addLine']);
        // constructor parameter properties become properties, like PHP promoted parameters
        self::assertSame('property', $symbols['packages\shared\domain\Order::id']);
        self::assertSame('property', $symbols['apps\web\services\OrderService::repository']);
    }

    public function testExcludesNodeModulesAndBuildOutput(): void
    {
        self::assertSame(0, (int) $this->db->scalar("SELECT count(*) FROM files WHERE path LIKE 'node_modules/%' OR path LIKE 'dist/%'"));
        self::assertSame(10, (int) $this->db->scalar('SELECT count(*) FROM files'));
        self::assertSame('ts', $this->db->getMeta('lang'));
    }

    public function testSignaturesDocsAndFlags(): void
    {
        $button = $this->db->one("SELECT signature, doc, flags FROM symbols WHERE fqn = 'packages\\shared\\components\\Button'");
        self::assertSame('A clickable button.', $button['doc']);
        self::assertStringContainsString('export', (string) $button['flags']);
        self::assertStringStartsWith('export function Button(', $button['signature']);

        $addLine = $this->db->one("SELECT signature, doc FROM symbols WHERE fqn = 'packages\\shared\\domain\\Order::addLine'");
        self::assertSame('public addLine(amount: number): void', $addLine['signature']);
        self::assertSame('Adds a line item.', $addLine['doc']);
    }

    public function testRelationsResolveThroughImports(): void
    {
        $relations = $this->db->all(
            "SELECT s.fqn, r.kind, r.target FROM relations r JOIN symbols s ON s.id = r.symbol_id ORDER BY s.fqn, r.kind, r.target",
        );
        $pairs = array_map(static fn (array $r) => "{$r['fqn']} {$r['kind']} {$r['target']}", $relations);

        self::assertContains('packages\shared\domain\Order implements packages\shared\domain\Order\HasTotal', $pairs);
        self::assertContains('packages\shared\domain\Order\SeasonalDiscount extends packages\shared\domain\Order\Discount', $pairs);
        self::assertContains('apps\web\services\InMemoryOrderRepository implements packages\shared\domain\Order\OrderRepository', $pairs);
    }

    public function testFindUsagesOfComponentIncludesImportsJsxAndReExports(): void
    {
        $result = $this->query->findUsages('Button');

        self::assertStringContainsString('Usages of packages\shared\components\Button (4):', $result);
        self::assertStringContainsString('apps/web/pages/CheckoutPage.tsx:1  (file scope)  [import]', $result, 'tsconfig paths alias @shared/* is resolved');
        self::assertStringContainsString('apps/web/pages/CheckoutPage.tsx:9  apps\web\pages\CheckoutPage  [jsx]', $result);
        self::assertStringContainsString('packages/shared/components/index.ts:1  (file scope)  [export]', $result);
    }

    public function testFindUsagesOfMemberListsUnknownReceiversSeparately(): void
    {
        $result = $this->query->findUsages('OrderRepository', 'save');

        self::assertStringStartsWith('Defined at packages/shared/domain/Order.ts:11  save(order: Order): void', $result);
        self::assertStringContainsString('apps/web/services/InMemoryOrderRepository.ts:6  InMemoryOrderRepository', $result);
        self::assertStringContainsString('apps/web/services/OrderService.ts:11  apps\web\services\OrderService::create  [call]', $result);
        self::assertStringContainsString("Calls to .save() on receivers of unknown type (may or may not be this method), 1:\napps/web/services/OrderService.ts:18  apps\\web\\services\\OrderService::cancel", $result);
    }

    public function testFindImplementationsIsTransitive(): void
    {
        $result = $this->query->findImplementations('HasTotal');

        self::assertStringContainsString('class packages\shared\domain\Order  (packages/shared/domain/Order.ts:22)', $result);
        self::assertStringContainsString('SeasonalDiscount  (packages/shared/domain/Order.ts:45)  via packages\shared\domain\Order\Discount', $result);
    }

    public function testDecoratorsAreAttributes(): void
    {
        self::assertStringContainsString('class apps\web\services\OrderService  #[injectable()]', $this->query->findByAttribute('injectable'));
        self::assertStringContainsString("property apps\\web\\services\\OrderService::repository  #[inject('OrderRepository')]", $this->query->findByAttribute('inject', 'OrderRepository'));
    }

    public function testClassOutline(): void
    {
        $outline = $this->query->classOutline('OrderService');

        self::assertStringContainsString('export class OrderService', $outline);
        self::assertStringContainsString('private readonly repository: OrderRepository  :6', $outline);
        self::assertStringContainsString('create(id: string): Order  :8', $outline);
    }

    public function testFileOutline(): void
    {
        $outline = $this->query->classOutline('packages/shared/components/Button.tsx');

        self::assertStringContainsString('export interface ButtonProps  :4-8', $outline);
        self::assertStringContainsString('export const IconButton = memo(', $outline);
    }

    public function testCalleesOfComponentAndMethod(): void
    {
        $page = $this->query->callees('CheckoutPage');
        self::assertStringContainsString('apps\web\hooks\useCart()', $page);
        self::assertStringContainsString('<packages\shared\components\Button>', $page);

        $create = $this->query->callees('OrderService::create');
        self::assertStringContainsString('new packages\shared\domain\Order', $create);
        self::assertStringContainsString('packages\shared\domain\Order\OrderRepository.save()', $create);
    }

    public function testSearchAndNamespaceTree(): void
    {
        self::assertStringContainsString('function apps\web\hooks\useCart  apps/web/hooks/useCart.ts:1', $this->query->searchSymbols('useCart'));
        self::assertStringContainsString('packages\shared\components (4)', $this->query->namespaceTree());
    }

    public function testParseErrorsAreRecordedAndPartialResultsKept(): void
    {
        $status = $this->query->indexStatus();

        self::assertStringContainsString('files with parse errors: 1', $status);
        self::assertStringContainsString('apps/web/pages/Broken.tsx:', $status);
        self::assertSame(1, (int) $this->db->scalar("SELECT count(*) FROM symbols WHERE fqn = 'apps\\web\\pages\\Broken\\StillIndexed'"));
    }

    public function testIncrementalUpdateAndDelete(): void
    {
        $this->writeFile('apps/web/pages/ThanksPage.tsx', <<<'TSX'
            import { Button } from '@shared/components';

            export function ThanksPage() {
                return <Button label="Home" />;
            }
            TSX);

        $result = $this->indexer->sync();
        self::assertSame(['apps/web/pages/ThanksPage.tsx'], $result->indexed);
        self::assertStringContainsString('apps/web/pages/ThanksPage.tsx:4  apps\web\pages\ThanksPage  [jsx]', $this->query->findUsages('Button'));

        self::assertFalse($this->indexer->sync()->changed());

        unlink($this->root.'/apps/web/hooks/useCart.ts');
        $result = $this->indexer->sync();
        self::assertSame(['apps/web/hooks/useCart.ts'], $result->removed);
        self::assertSame(0, (int) $this->db->scalar("SELECT count(*) FROM symbols WHERE fqn LIKE 'apps\\web\\hooks%'"));
    }

    public function testMarkdownExport(): void
    {
        $exporter = new MarkdownExporter($this->db, $this->query, 24000);
        $dir = (string) $this->config->outputDir;

        self::assertGreaterThan(0, $exporter->export($dir, 'fixture'));
        self::assertSame(0, $exporter->export($dir, 'fixture'), 'unchanged index must not rewrite files');
        self::assertStringContainsString('Generated from the TypeScript AST', (string) file_get_contents($dir.'/OVERVIEW.md'));
        $components = (string) file_get_contents($dir.'/modules/packages.shared.components.md');
        self::assertStringContainsString('## Button (component)', $components);
        self::assertStringContainsString('- `label: string`', $components);
    }

    public function testMcpUsesTypeScriptWording(): void
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
            $receive = static fn (): array => json_decode((string) fgets($pipes[1]), true, flags: \JSON_THROW_ON_ERROR);

            $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]]);
            self::assertStringContainsString('TypeScript', $receive()['result']['instructions']);
            $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            $tools = array_column($receive()['result']['tools'], null, 'name');
            self::assertCount(8, $tools);
            self::assertStringContainsString('decorator', $tools['find_by_attribute']['description']);

            $send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'find_usages',
                'arguments' => ['fqcn' => 'useCart'],
            ]]);
            self::assertStringContainsString('apps/web/pages/CheckoutPage.tsx:5', $receive()['result']['content'][0]['text']);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testLanguageDetection(): void
    {
        self::assertSame('ts', Config::detectLanguage($this->root));
        self::assertSame('php', Config::detectLanguage(__DIR__.'/fixtures/sample-project'));
    }
}
