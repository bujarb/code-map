export function memo<T>(component: T): T {
    return component;
}

export function forwardRef<T>(render: T): T {
    return render;
}

export function createElement(type: unknown, props: unknown): unknown {
    return { type, props };
}
