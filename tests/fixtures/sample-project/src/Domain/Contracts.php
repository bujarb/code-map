<?php

declare(strict_types=1);

namespace Shop\Domain;

interface HasTotal
{
    public function total(): int;
}

interface OrderRepository
{
    public function save(Order $order): void;

    public function find(string $id): ?Order;
}

trait Timestamps
{
    private ?\DateTimeImmutable $updatedAt = null;

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

enum OrderStatus: string
{
    case Open = 'open';
    case Cancelled = 'cancelled';
}

abstract class Discount implements HasTotal
{
}

class SeasonalDiscount extends Discount
{
    public function total(): int
    {
        return 10;
    }
}

function format_order_id(string $id): string
{
    return Order::PREFIX.$id;
}
