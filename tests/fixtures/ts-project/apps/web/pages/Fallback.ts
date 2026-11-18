import { createElement } from '@shared/components/memo';
import { Button } from '@shared/components';

export function Fallback() {
    return createElement(Button, { label: 'Retry' });
}
