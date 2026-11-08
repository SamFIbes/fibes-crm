import type { CSSProperties, ReactNode, SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/** Solid surface from the classic FIBES desk. It does not use the frosted card. */
const solid: CSSProperties = {
    backgroundColor: 'var(--card)',
    backdropFilter: 'none',
    WebkitBackdropFilter: 'none',
};

export function SolidCard({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <section className={cn('rounded-card border border-line shadow-card', className)} style={solid}>
            {children}
        </section>
    );
}

export const solidField = 'h-10 w-full rounded-lg border px-3 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50';

export const solidFieldStyle: CSSProperties = {
    backgroundColor: 'var(--card)',
    borderColor: 'var(--line-2)',
    color: 'var(--text)',
    backdropFilter: 'none',
};

export function SolidSelect(props: SelectHTMLAttributes<HTMLSelectElement>) {
    return <select {...props} className={cn(solidField, props.className)} style={{ ...solidFieldStyle, ...props.style }} />;
}

const tones = {
    good: 'bg-good-bg text-good',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    neutral: 'bg-well text-muted',
} as const;

export function SolidBadge({ tone = 'neutral', children }: { tone?: keyof typeof tones; children: ReactNode }) {
    return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold leading-5', tones[tone])}>{children}</span>;
}
