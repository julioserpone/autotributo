/**
 * Entorno de Vitest para componentes.
 *
 * jsdom no implementa APIs de layout que reka-ui necesita para posicionar
 * portales y manejar el foco. Los stubs son deliberadamente mínimos: los
 * tests verifican comportamiento accesible, no geometría.
 */

class ResizeObserverStub implements ResizeObserver {
    public observe(): void {}

    public unobserve(): void {}

    public disconnect(): void {}
}

class IntersectionObserverStub implements IntersectionObserver {
    public readonly root: Element | Document | null = null;
    public readonly rootMargin: string = '';
    public readonly thresholds: readonly number[] = [];
    public readonly scrollMargin: string = '';

    public observe(): void {}

    public unobserve(): void {}

    public disconnect(): void {}

    public takeRecords(): IntersectionObserverEntry[] {
        return [];
    }
}

if (!('ResizeObserver' in globalThis)) {
    globalThis.ResizeObserver = ResizeObserverStub;
}

if (!('IntersectionObserver' in globalThis)) {
    globalThis.IntersectionObserver = IntersectionObserverStub;
}

if (!('PointerEvent' in globalThis)) {
    globalThis.PointerEvent = MouseEvent as unknown as typeof PointerEvent;
}

if (typeof window !== 'undefined') {
    window.matchMedia ??= ((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
    })) as typeof window.matchMedia;

    window.HTMLElement.prototype.scrollIntoView ??= () => {};

    window.HTMLElement.prototype.hasPointerCapture ??= () => false;
    window.HTMLElement.prototype.setPointerCapture ??= () => {};
    window.HTMLElement.prototype.releasePointerCapture ??= () => {};
}
