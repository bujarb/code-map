<?php

declare(strict_types=1);

namespace Shop\Domain;

/**
 * A customer order.
 *
 * Longer description that should not end up in the summary.
 */
final class Order implements HasTotal
{
    use Timestamps;

    public const string PREFIX = 'ORD-';

    private array $lines = [];

    public function __construct(
        public readonly string $id,
        private OrderStatus $status = OrderStatus::Open,
    ) {
    }

    /** Adds a line item. */
    public function addLine(string $sku, int $quantity): void
    {
        $this->lines[] = [$sku, $quantity];
        $this->touch();
    }

    public function total(): int
    {
        return \count($this->lines);
    }

    public function cancel(): void
    {
        $this->status = OrderStatus::Cancelled;
    }
}
