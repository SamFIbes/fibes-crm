import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { BrandLogo, Logo } from '@/components/brand';
import { ThemeProvider } from '@/lib/theme';

function renderLogo(node: ReactNode) {
    return render(<ThemeProvider>{node}</ThemeProvider>);
}

describe('official logo', () => {
    it('uses the official artwork and does not draw a mark', () => {
        const { container } = renderLogo(<Logo />);
        const image = screen.getByRole('img', { name: /fibes/i });

        expect(image).toHaveAttribute('src', expect.stringMatching(/^\/brand\/logo-compact-on-(light|dark)\.png$/));
        expect(image.closest('[dir="ltr"]')).not.toBeNull();
        expect(container.querySelector('svg')).toBeNull();
    });

    it('keeps the artwork left-to-right inside an Arabic page', () => {
        const { container } = renderLogo(
            <div dir="rtl">
                <BrandLogo variant="full" tone="dark" />
            </div>,
        );

        const artwork = container.querySelector('[data-brand="logo-full"]');
        expect(artwork).toHaveAttribute('dir', 'ltr');
        expect(artwork?.querySelector('img')).toHaveAttribute('src', '/brand/logo-full-on-dark.png');
    });
});
