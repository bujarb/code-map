import { Button } from '@shared/components';
import { useCart } from '../hooks/useCart';

export function renderCheckout() {
    useCart();
    return <Button label="Test" />;
}
