export function injectable() {
    return (target: unknown) => target;
}

export function inject(token: string) {
    return (_target: unknown, _key: unknown, _index: number) => token;
}
