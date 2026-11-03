import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

function parseBlock(css: string, selector: string): Record<string, string> {
    const match = css.match(new RegExp(`${selector}\\s*\\{([\\s\\S]*?)\\n\\}`));
    const body = match?.[1] ?? '';
    const colors: Record<string, string> = {};

    for (const entry of body.matchAll(/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/g)) {
        colors[entry[1]] = entry[2].toLowerCase();
    }

    return colors;
}

function hex([red, green, blue]: [number, number, number]): string {
    return `#${[red, green, blue].map((part) => part.toString(16).padStart(2, '0')).join('')}`;
}

function rgb(value: string): [number, number, number] {
    const hex = value.replace('#', '');

    return [Number.parseInt(hex.slice(0, 2), 16), Number.parseInt(hex.slice(2, 4), 16), Number.parseInt(hex.slice(4, 6), 16)];
}

function channel(value: number): number {
    const scaled = value / 255;

    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4;
}

function luminance(color: [number, number, number]): number {
    return 0.2126 * channel(color[0]) + 0.7152 * channel(color[1]) + 0.0722 * channel(color[2]);
}

function contrast(a: string, b: string): number {
    const lighter = Math.max(luminance(rgb(a)), luminance(rgb(b)));
    const darker = Math.min(luminance(rgb(a)), luminance(rgb(b)));

    return (lighter + 0.05) / (darker + 0.05);
}

describe('brand contrast', () => {
    const css = readFileSync(resolve(process.cwd(), 'resources/css/tokens.css'), 'utf8');
    const light = parseBlock(css, ':root');
    const dark = parseBlock(css, 'html\\.dark');

    it('uses the Tend clinical palette', () => {
        expect(light.bg).toBe('#eef2ef');
        expect(light.text).toBe('#10231f');
        expect(light.accent).toBe('#0d5c55');
        expect(light.side).toBe('#0f2d29');
        expect(light['side-pill']).toBe('#c4ecd9');
        expect(dark.bg).toBe('#0b1312');
        expect(dark.accent).toBe('#5cd0b4');
        expect(dark.side).toBe('#0e1918');
        expect(hex(rgb('#10231f'))).toBe('#10231f');
    });

    it('meets WCAG AA for text on light surfaces', () => {
        expect(contrast(light.text, light.bg)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light.text, light.card)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light.muted, light.bg)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light.muted, light.card)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light['on-accent'], light.accent)).toBeGreaterThanOrEqual(4.5);
        expect(contrast('#ffffff', light.bad)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light['side-text'], light.side)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(light.ring, light.bg)).toBeGreaterThanOrEqual(3);
    });

    it('meets WCAG AA for text on dark surfaces', () => {
        expect(contrast(dark.text, dark.bg)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark.text, dark.card)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark.muted, dark.bg)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark.muted, dark.card)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark['on-accent'], dark.accent)).toBeGreaterThanOrEqual(4.5);
        expect(contrast('#06201c', dark.bad)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark['side-text'] ?? light['side-text'], dark.side)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(dark.ring, dark.bg)).toBeGreaterThanOrEqual(3);
    });
});
