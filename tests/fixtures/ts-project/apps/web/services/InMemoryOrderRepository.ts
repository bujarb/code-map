import type { Order, OrderRepository } from '@shared/domain/Order';

export class InMemoryOrderRepository implements OrderRepository {
    private orders = new Map<string, Order>();

    save(order: Order): void {
        this.orders.set(order.id, order);
    }

    find(id: string): Order | undefined {
        return this.orders.get(id);
    }
}
