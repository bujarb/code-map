import { OrderScope } from '@shared/domain/Order';

export function isBusiness(scope: OrderScope): boolean {
    return scope === OrderScope.Business;
}
