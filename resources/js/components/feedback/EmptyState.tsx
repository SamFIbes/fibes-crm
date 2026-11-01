import type { ReactNode } from 'react';

export function EmptyState({ icon, eyebrow, title, body }: { icon: ReactNode; eyebrow?: string; title: string; body: string }) {
    return (
        <div className="mx-auto flex max-w-lg flex-col items-center px-4 py-16 text-center">
            <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-gradient text-[#0E0F2D]">{icon}</div>
            {eyebrow ? <p className="text-xs font-medium uppercase tracking-[0.14em] text-muted-foreground">{eyebrow}</p> : null}
            <h1 className="mt-2 text-2xl font-semibold tracking-tight">{title}</h1>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">{body}</p>
        </div>
    );
}
