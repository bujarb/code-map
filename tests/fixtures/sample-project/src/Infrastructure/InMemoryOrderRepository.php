<?php

declare(strict_types=1);

namespace Shop\Infrastructure;

use Shop\Domain\Order;
use Shop\Domain\OrderRepository;

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    public function save(Order $order): void
    {
        $this->orders[$order->id] = $order;
    }

    public function find(string $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }
}
