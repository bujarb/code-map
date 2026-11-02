import { inject, injectable } from './di';
import { Order, formatOrderId, type OrderRepository } from '@shared/domain/Order';

@injectable()
export class OrderService {
    constructor(@inject('OrderRepository') private readonly repository: OrderRepository) {}

    create(id: string): Order {
        const order = new Order(id);
        order.addLine(10);
        this.repository.save(order);
        console.log(formatOrderId(order.id));
        return order;
    }

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    cancel(order: any): void {
        order.save();
    }
}
