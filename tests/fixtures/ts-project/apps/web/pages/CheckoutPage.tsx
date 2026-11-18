import { Button, IconButton } from '@shared/components';
import { useCart } from '../hooks/useCart';

export default function CheckoutPage() {
    const cart = useCart();

    return (
        <div>
            <Button label="Pay" onClick={() => cart.pay()} />
            <IconButton label="Close" />
        </div>
    );
}
