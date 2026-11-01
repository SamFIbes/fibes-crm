import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';

afterEach(() => {
    cleanup();
});

class ResizeObserverStub {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
}

if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver;
}

if (typeof Element !== 'undefined' && typeof Element.prototype.scrollIntoView !== 'function') {
    Element.prototype.scrollIntoView = () => undefined;
}

if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (query: string) =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addListener: () => undefined,
            removeListener: () => undefined,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            dispatchEvent: () => false,
        }) as MediaQueryList;
}
