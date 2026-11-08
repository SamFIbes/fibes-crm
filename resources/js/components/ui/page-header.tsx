import type { ReactNode } from 'react';

export function PageHeader({ title, sub, actions, crumbs }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode; crumbs?: ReactNode }) {
    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                {crumbs ? <div className="mb-2 flex items-center gap-1.5 text-sm text-muted">{crumbs}</div> : null}
                <h1 className="text-[28px] font-bold leading-[1.1] tracking-[-0.035em] sm:text-display">
                    {title}
                </h1>
                {sub ? <p className="mt-1.5 max-w-2xl text-base text-muted">{sub}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}
