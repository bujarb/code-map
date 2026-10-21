<?php

declare(strict_types=1);

namespace CodeMap\Tests;

final class IndexerTest extends IndexTestCase
{
    public function testExtractsDeclarations(): void
    {
        $symbols = array_column($this->db->all('SELECT fqn, kind FROM symbols'), 'kind', 'fqn');

        self::assertSame('class', $symbols['Shop\Domain\Order']);
        self::assertSame('interface', $symbols['Shop\Domain\OrderRepository']);
        self::assertSame('trait', $symbols['Shop\Domain\Timestamps']);
        self::assertSame('enum', $symbols['Shop\Domain\OrderStatus']);
        self::assertSame('case', $symbols['Shop\Domain\OrderStatus::Cancelled']);
        self::assertSame('function', $symbols['Shop\Domain\format_order_id']);
        self::assertSame('method', $symbols['Shop\Domain\Order::addLine']);
        self::assertSame('constant', $symbols['Shop\Domain\Order::PREFIX']);
        self::assertSame('property', $symbols['Shop\Domain\Order::$lines']);
        // promoted constructor parameters become properties
        self::assertSame('property', $symbols['Shop\Http\OrderController::$repository']);
    }

    public function testExcludesVendor(): void
    {
        self::assertSame(0, (int) $this->db->scalar("SELECT count(*) FROM files WHERE path LIKE 'vendor/%'"));
        self::assertSame(4, (int) $this->db->scalar('SELECT count(*) FROM files'));
    }

    public function testSignaturesAndDocs(): void
    {
        $row = $this->db->one("SELECT signature, doc FROM symbols WHERE fqn = 'Shop\\Domain\\Order::addLine'");
        self::assertSame('public function addLine(string $sku, int $quantity): void', $row['signature']);
        self::assertSame('Adds a line item.', $row['doc']);

        $class = $this->db->one("SELECT signature, doc FROM symbols WHERE fqn = 'Shop\\Domain\\Order'");
        self::assertSame('final class Shop\Domain\Order implements Shop\Domain\HasTotal', $class['signature']);
        self::assertSame('A customer order.', $class['doc'], 'only the first paragraph is kept');
    }

    public function testRelations(): void
    {
        $relations = $this->db->all('SELECT s.fqn, r.kind, r.target FROM relations r JOIN symbols s ON s.id = r.symbol_id ORDER BY s.fqn, r.kind');
        $flat = array_map(static fn (array $r) => "{$r['fqn']} {$r['kind']} {$r['target']}", $relations);

        self::assertContains('Shop\Domain\Order implements Shop\Domain\HasTotal', $flat);
        self::assertContains('Shop\Domain\Order uses_trait Shop\Domain\Timestamps', $flat);
        self::assertContains('Shop\Domain\SeasonalDiscount extends Shop\Domain\Discount', $flat);
        self::assertContains('Shop\Infrastructure\InMemoryOrderRepository implements Shop\Domain\OrderRepository', $flat);
    }

    public function testResolvesReceiverTypes(): void
    {
        $refs = $this->db->all(
            "SELECT r.kind, r.target_class, r.target_member FROM refs r JOIN symbols s ON s.id = r.symbol_id
             WHERE s.fqn = 'Shop\\Http\\OrderController::create' ORDER BY r.line",
        );
        $flat = array_map(static fn (array $r) => $r['kind'].' '.($r['target_class'] ?? '?').' '.($r['target_member'] ?? ''), $refs);

        self::assertContains('new Shop\Domain\Order ', $flat);
        self::assertContains('call Shop\Domain\Order addLine', $flat, 'variable assigned from new');
        self::assertContains('call Shop\Domain\OrderRepository save', $flat, 'promoted constructor property');
        self::assertContains('func Shop\Domain\format_order_id ', $flat, 'imported function');
        self::assertNotContains('func count ', $flat);
    }

    public function testUnknownReceiverIsStoredWithoutClass(): void
    {
        $count = (int) $this->db->scalar("SELECT count(*) FROM refs WHERE kind = 'call' AND target_class IS NULL AND target_member = 'save'");
        self::assertSame(1, $count);
    }

    public function testAttributes(): void
    {
        $args = $this->db->scalar(
            "SELECT a.args FROM attributes a JOIN symbols s ON s.id = a.symbol_id WHERE s.fqn = 'Shop\\Http\\OrderController::cancel'",
        );
        self::assertSame("'/orders/{id}/cancel', name: 'order_cancel', methods: ['POST']", $args);
    }

    public function testIncrementalUpdateOnlyTouchesChangedFile(): void
    {
        $untouched = $this->db->scalar("SELECT id FROM symbols WHERE fqn = 'Shop\\Domain\\Order'");

        $this->writeFile('src/Infrastructure/InMemoryOrderRepository.php', <<<'PHP'
            <?php
            namespace Shop\Infrastructure;

            final class InMemoryOrderRepository implements \Shop\Domain\OrderRepository
            {
                public function save(\Shop\Domain\Order $order): void {}
                public function find(string $id): ?\Shop\Domain\Order { return null; }
                public function clear(): void {}
            }
            PHP);

        $result = $this->indexer->sync();

        self::assertSame(['src/Infrastructure/InMemoryOrderRepository.php'], $result->indexed);
        self::assertSame($untouched, $this->db->scalar("SELECT id FROM symbols WHERE fqn = 'Shop\\Domain\\Order'"));
        self::assertSame(1, (int) $this->db->scalar("SELECT count(*) FROM symbols WHERE fqn = 'Shop\\Infrastructure\\InMemoryOrderRepository::clear'"));
        self::assertSame(0, (int) $this->db->scalar("SELECT count(*) FROM symbols WHERE fqn = 'Shop\\Infrastructure\\InMemoryOrderRepository::\$orders'"));
    }

    public function testNoopSyncReindexesNothing(): void
    {
        $result = $this->indexer->sync();
        self::assertFalse($result->changed());

        // A touched but unchanged file is re-hashed, not re-parsed.
        touch($this->root.'/src/Domain/Order.php', time() + 10);
        clearstatcache();
        self::assertFalse($this->indexer->sync()->changed());
    }

    public function testDeletedFileIsRemovedWithItsSymbolsAndSearchEntries(): void
    {
        unlink($this->root.'/src/Infrastructure/InMemoryOrderRepository.php');
        $result = $this->indexer->sync();

        self::assertSame(['src/Infrastructure/InMemoryOrderRepository.php'], $result->removed);
        self::assertSame(0, (int) $this->db->scalar("SELECT count(*) FROM symbols WHERE fqn LIKE 'Shop\\Infrastructure%'"));
        self::assertSame(
            (int) $this->db->scalar('SELECT count(*) FROM symbols'),
            (int) $this->db->scalar('SELECT count(*) FROM symbols_fts'),
            'search index must not keep rows for deleted symbols',
        );
    }

    public function testParseErrorsAreRecordedAndPartialResultsKept(): void
    {
        $this->writeFile('src/Broken.php', "<?php\nnamespace Shop;\nclass Broken {\n  public function ok(): void {}\n  public function bad( {\n}\n");

        $result = $this->indexer->sync();

        self::assertArrayHasKey('src/Broken.php', $result->errors);
        self::assertNotNull($this->db->scalar("SELECT parse_error FROM files WHERE path = 'src/Broken.php'"));
    }
}
