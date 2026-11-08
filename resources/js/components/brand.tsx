import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { prefersReducedMotion } from '@/lib/motion';
import { useTheme } from '@/lib/theme';

/*
 * Official FIBES artwork from Sam's brand files. Never redraw, stretch, recolour, or mirror it.
 * The mark stays left-to-right even when the rest of the page is Arabic.
 */
const ICON_RATIO = 125 / 160;

type Tone = 'dark' | 'light' | 'auto';

function useTone(tone: Tone): 'dark' | 'light' {
    const { theme } = useTheme();

    return tone === 'auto' ? (theme === 'dark' ? 'dark' : 'light') : tone;
}

function useEntrance(animated?: boolean) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!animated || !ref.current || prefersReducedMotion()) {
            return;
        }

        const root = ref.current;
        let revert: () => void = () => {};
        let cancelled = false;

        void import('gsap').then(({ default: gsap }) => {
            if (cancelled) {
                return;
            }

            const ctx = gsap.context(() => {
                gsap.fromTo('[data-logo-art]', { opacity: 0, scale: 0.92, filter: 'blur(10px)' }, { opacity: 1, scale: 1, filter: 'blur(0px)', duration: 1.3, ease: 'expo.out' });
                gsap.fromTo('[data-logo-glow]', { opacity: 0 }, { opacity: 0.85, duration: 1.6, delay: 0.3, ease: 'sine.out' });
                gsap.to('[data-logo-glow]', { opacity: 0.45, scale: 1.08, duration: 3.2, delay: 1.9, ease: 'sine.inOut', yoyo: true, repeat: -1 });
            }, root);
            revert = () => ctx.revert();
        });

        return () => {
            cancelled = true;
            revert();
        };
    }, [animated]);

    return ref;
}

export function BrandMark({ size = 32, animated, glow, className }: { size?: number; animated?: boolean; glow?: boolean; className?: string }) {
    const ref = useEntrance(animated);

    return (
        <div ref={ref} data-brand="icon" dir="ltr" className={cn('relative shrink-0', className)} style={{ width: size, height: size }} aria-hidden>
            {(glow || animated) && <span data-logo-glow className="pointer-events-none absolute inset-[12%] rounded-full bg-[radial-gradient(circle,rgb(31_209_122/0.35),transparent_70%)] blur-md" />}
            <img data-logo-art src="/brand/fibes-icon.png" alt="" width={size} height={size} draggable={false} className="relative size-full object-contain select-none" />
        </div>
    );
}

export function BrandLogo({
    variant = 'full',
    tone = 'auto',
    height = 48,
    animated,
    className,
    label = 'FIBES — Next-Generation Solutions',
}: {
    variant?: 'full' | 'compact';
    tone?: Tone;
    height?: number;
    animated?: boolean;
    className?: string;
    label?: string;
}) {
    const artwork = useTone(tone);
    const ref = useEntrance(animated);
    const iconWidth = Math.round(height * ICON_RATIO);

    return (
        <div ref={ref} data-testid="brand-logo" data-brand={`logo-${variant}`} data-tone={artwork} dir="ltr" className={cn('relative flex items-center gap-2.5 text-current', className)} style={{ height }}>
            {animated && <span data-logo-glow aria-hidden className="pointer-events-none absolute start-0 top-[10%] h-[80%] w-16 rounded-full bg-[radial-gradient(circle,rgb(31_209_122/0.32),transparent_70%)] blur-2xl" />}
            <img data-logo-art src="/brand/fibes-icon.png" alt={label} width={iconWidth} height={height} draggable={false} className="relative h-full w-auto select-none" />
            <span aria-hidden="true" className="min-w-0 leading-none" style={{ fontFamily: 'var(--font-heading)' }}>
                <span className="block font-bold tracking-tight" style={{ fontSize: Math.max(18, Math.round(height * 0.42)) }}>fibes</span>
                {variant === 'full' ? <span className="mt-1 block font-semibold uppercase tracking-[0.14em] opacity-80" style={{ fontSize: Math.max(9, Math.round(height * 0.14)) }}>Next generation solutions</span> : null}
            </span>
        </div>
    );
}

export function Wordmark({ height = 18, className }: { height?: number; tone?: Tone; className?: string }) {
    return <img src="/brand/fibes-icon.png" alt="fibes" draggable={false} dir="ltr" className={cn('inline-block select-none', className)} style={{ height, width: height * ICON_RATIO }} />;
}

/** Older screens import Logo. It now shows the official artwork and never a drawn mark. */
export function Logo({
    variant = 'lockup',
    tone = 'auto',
    className,
}: {
    variant?: 'mark' | 'lockup' | 'full' | 'compact';
    tone?: 'auto' | 'on-navy' | 'dark' | 'light';
    className?: string;
}) {
    const mapped = tone === 'on-navy' || tone === 'dark' ? 'dark' : tone === 'light' ? 'light' : 'auto';

    if (variant === 'mark') {
        return <BrandMark size={36} className={className} />;
    }

    return <BrandLogo variant={variant === 'full' ? 'full' : 'compact'} tone={mapped} height={variant === 'full' ? 72 : 44} className={className} />;
}
