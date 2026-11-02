export enum OrderStatus {
    Open = 'open',
    Cancelled = 'cancelled',
}

export interface HasTotal {
    total(): number;
}

export interface OrderRepository {
    save(order: Order): void;
    find(id: string): Order | undefined;
}

export type OrderId = string;

/**
 * A customer order.
 *
 * Only the first paragraph is kept.
 */
export class Order implements HasTotal {
    private lines: number[] = [];

    constructor(public readonly id: OrderId, private status: OrderStatus = OrderStatus.Open) {}

    /** Adds a line item. */
    addLine(amount: number): void {
        this.lines.push(amount);
    }

    total(): number {
        return this.lines.reduce((sum, line) => sum + line, 0);
    }

    cancel(): void {
        this.status = OrderStatus.Cancelled;
    }
}

export abstract class Discount implements HasTotal {
    abstract total(): number;
}

export class SeasonalDiscount extends Discount {
    total(): number {
        return 5;
    }
}

export function formatOrderId(id: OrderId): string {
    return `#${id}`;
}
