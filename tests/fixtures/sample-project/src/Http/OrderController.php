<?php

declare(strict_types=1);

namespace Shop\Http;

use Shop\Domain\Order;
use Shop\Domain\OrderRepository;
use Symfony\Component\Routing\Attribute\Route;

use function Shop\Domain\format_order_id;

#[Route('/orders')]
final class OrderController
{
    public function __construct(private readonly OrderRepository $repository)
    {
    }

    #[Route('/orders/{id}', name: 'order_create', methods: ['POST'])]
    public function create(string $id): string
    {
        $order = new Order($id);
        $order->addLine('sku-1', 2);
        $this->repository->save($order);

        return format_order_id($order->id);
    }

    #[Route('/orders/{id}/cancel', name: 'order_cancel', methods: ['POST'])]
    public function cancel(string $id, mixed $unknown): void
    {
        $order = $this->repository->find($id);
        $order?->cancel();
        $unknown->save();

        try {
            $this->repository->save($order);
        } catch (\RuntimeException $e) {
        }
    }
}
