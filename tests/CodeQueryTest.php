<?php

declare(strict_types=1);

namespace CodeMap\Tests;

use CodeMap\Export\MarkdownExporter;

final class CodeQueryTest extends IndexTestCase
{
    public function testClassOutlineAcceptsShortName(): void
    {
        $outline = $this->query->classOutline('OrderController');

        self::assertStringContainsString('final class Shop\Http\OrderController', $outline);
        self::assertStringContainsString('private readonly OrderRepository $repository', $outline);
        self::assertStringContainsString("#[Route('/orders/{id}'", $outline);
    }

    public function testUnknownClassSuggestsAlternatives(): void
    {
        $result = $this->query->classOutline('Shop\Http\OrderControler');

        self::assertStringContainsString('not found', $result);
    }

    public function testTransitiveImplementations(): void
    {
        $result = $this->query->findImplementations('Shop\Domain\HasTotal');

        self::assertStringContainsString('class Shop\Domain\Order ', $result);
        self::assertStringContainsString('class Shop\Domain\SeasonalDiscount ', $result);
        self::assertStringContainsString('via Shop\Domain\Discount', $result);
        self::assertStringNotContainsString('InMemoryOrderRepository', $result);
    }

    public function testFindUsagesOfMemberListsUnknownReceiversInline(): void
    {
        $result = $this->query->findUsages('OrderRepository', 'save');

        self::assertStringContainsString('src/Http/OrderController.php:25  Shop\Http\OrderController::create  [call]', $result);
        self::assertStringContainsString("Calls to ->save() on receivers of unknown type (may or may not be this method), 1:\nsrc/Http/OrderController.php:35  Shop\\Http\\OrderController::cancel", $result);
    }

    public function testFindUsagesOfImplementationIncludesCallsThroughInterface(): void
    {
        $result = $this->query->findUsages('InMemoryOrderRepository', 'save');

        self::assertStringContainsString('src/Http/OrderController.php:25  Shop\Http\OrderController::create  [call via OrderRepository]', $result);
    }

    public function testFindUsagesShowsDefinitionAndOverrides(): void
    {
        $result = $this->query->findUsages('OrderRepository', 'save');

        self::assertStringStartsWith('Defined at src/Domain/Contracts.php:14  public function save(Order $order): void', $result);
        self::assertStringContainsString("Also declared in subtypes (1):\n  src/Infrastructure/InMemoryOrderRepository.php:15  InMemoryOrderRepository: public function save(Order \$order): void", $result);
    }

    public function testFindUsagesShowsWhatTheMethodCalls(): void
    {
        $result = $this->query->findUsages('OrderController', 'create');

        self::assertStringContainsString('It calls: new Order :23, Order::addLine() :24, OrderRepository::save() :25, format_order_id() :27', $result);
    }

    public function testClassOutlineMemberFilter(): void
    {
        $result = $this->query->classOutline('Order', 'line');

        self::assertStringContainsString('addLine(string $sku, int $quantity): void', $result);
        self::assertStringContainsString('$lines', $result);
        self::assertStringNotContainsString('cancel', $result);
        self::assertStringContainsString('No member of Shop\Domain\Order matches "nope"', $this->query->classOutline('Order', 'nope'));
    }

    public function testClassOutlineIsCompactForLargeClasses(): void
    {
        $methods = implode("\n", array_map(static fn (int $i) => "    public function method{$i}(string \$a): void {}", range(1, 45)));
        $this->writeFile('src/Big.php', "<?php\nnamespace Shop;\nfinal class Big\n{\n{$methods}\n}\n");
        $this->indexer->sync();

        $compact = $this->query->classOutline('Big');
        self::assertStringContainsString('methods (45, names only; pass member="part of a name" for signatures):', $compact);
        self::assertStringContainsString('method1 :5, method2 :6', $compact);
        self::assertStringNotContainsString('string $a', $compact);

        self::assertStringContainsString('method12(string $a): void', $this->query->classOutline('Big', 'method12'));
    }

    public function testFindUsagesShowsInheritedDefinition(): void
    {
        // Discount declares no total() itself; it comes from the HasTotal interface.
        self::assertStringStartsWith(
            'Defined at src/Domain/Contracts.php:9  public function total(): int  (inherited from HasTotal)',
            $this->query->findUsages('Discount', 'total'),
        );
    }

    public function testClassOutlineShortensMemberSignatures(): void
    {
        $outline = $this->query->classOutline('OrderController');

        self::assertStringStartsWith('final class Shop\Http\OrderController', $outline, 'header keeps the full name');
        self::assertStringContainsString('private readonly OrderRepository $repository', $outline);
        self::assertStringNotContainsString('Shop\Domain\OrderRepository $repository', $outline);
    }

    public function testFindUsagesSaysWhenListIsComplete(): void
    {
        self::assertStringContainsString('this list is complete', $this->query->findUsages('Order', 'addLine'));
    }

    public function testAmbiguousShortNameListsCandidates(): void
    {
        $this->writeFile('src/Http/Order.php', "<?php\nnamespace Shop\\Http;\nfinal class Order {}\n");
        $this->indexer->sync();

        $result = $this->query->findUsages('Order', 'addLine');

        self::assertStringContainsString('"Order" is ambiguous', $result);
        self::assertStringContainsString('class Shop\Domain\Order', $result);
        self::assertStringContainsString('class Shop\Http\Order', $result);
    }

    public function testClassOutlineAcceptsFilePath(): void
    {
        $result = $this->query->classOutline('src/Http/OrderController.php');

        self::assertStringStartsWith('file: src/Http/OrderController.php', $result);
    }

    public function testCalleesAcceptsShortClassName(): void
    {
        self::assertStringContainsString('Shop\Domain\OrderRepository->save()', $this->query->callees('OrderController::create'));
    }

    public function testFindUsagesSkipsSelfReferences(): void
    {
        $result = $this->query->findUsages('Shop\Domain\Order');

        self::assertStringContainsString('OrderController::create  [new]', $result);
        self::assertStringNotContainsString('Order::addLine', $result);
    }

    public function testSearchSplitsCamelCase(): void
    {
        $result = $this->query->searchSymbols('OrderRepo');

        self::assertStringStartsWith('interface Shop\Domain\OrderRepository', $result);
        self::assertStringContainsString('class Shop\Infrastructure\InMemoryOrderRepository', $result);
    }

    public function testSearchTreatsOperatorsAsText(): void
    {
        self::assertIsString($this->query->searchSymbols('"order" OR NEAR('));
    }

    public function testFindByAttributeFiltersOnArguments(): void
    {
        $result = $this->query->findByAttribute('Route', '/cancel');

        self::assertStringContainsString('OrderController::cancel', $result);
        self::assertStringNotContainsString('OrderController::create', $result);
    }

    public function testNamespaceTreeAggregatesCounts(): void
    {
        self::assertSame("Shop (9)\n  Shop\\Domain (7)\n  Shop\\Http (1)\n  Shop\\Infrastructure (1)", $this->query->namespaceTree());
        self::assertSame('Shop\Domain (7)', $this->query->namespaceTree('Shop\Domain'));
    }

    public function testCallees(): void
    {
        $result = $this->query->callees('Shop\Http\OrderController::cancel');

        self::assertStringContainsString('Shop\Domain\OrderRepository->find()', $result);
        self::assertStringContainsString('RuntimeException [catch]', $result);
    }

    public function testMarkdownExportIsStable(): void
    {
        $exporter = new MarkdownExporter($this->db, $this->query, 24000);
        $dir = $this->config->outputDir;

        self::assertSame(4, $exporter->export($dir, 'sample'));
        self::assertSame(0, $exporter->export($dir, 'sample'), 'unchanged index must not rewrite files');
        self::assertFileExists($dir.'/namespaces/Shop.Domain.md');
        self::assertStringContainsString('`function addLine(string $sku, int $quantity): void` — Adds a line item.', (string) file_get_contents($dir.'/namespaces/Shop.Domain.md'));
    }
}
