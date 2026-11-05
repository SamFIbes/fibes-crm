import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Tone = 'good' | 'warn' | 'bad' | 'info' | 'violet' | 'neutral' | 'accent';

const tones: Record<Tone, string> = {
    good: 'bg-good-bg text-good ring-1 ring-good/30 shadow-[0_0_16px_-6px_var(--good)]',
    warn: 'bg-warn-bg text-warn ring-1 ring-warn/30 shadow-[0_0_16px_-6px_var(--warn)]',
    bad: 'bg-bad-bg text-bad ring-1 ring-bad/35 shadow-[0_0_16px_-6px_var(--bad)]',
    info: 'bg-info-bg text-info ring-1 ring-info/30 shadow-[0_0_16px_-6px_var(--info)]',
    violet: 'bg-violet-bg text-violet ring-1 ring-violet/30 shadow-[0_0_16px_-6px_var(--violet)]',
    neutral: 'bg-well text-muted ring-1 ring-line',
    accent: 'bg-brand-gradient text-navy shadow-[var(--glow-mint)]',
};

export function Badge({ tone = 'neutral', dot, className, children }: { tone?: Tone; dot?: boolean; className?: string; children: ReactNode }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-2xs font-semibold leading-5', tones[tone], className)}>
            {dot ? <span className="size-1.5 rounded-full bg-current" /> : null}
            {children}
        </span>
    );
}
