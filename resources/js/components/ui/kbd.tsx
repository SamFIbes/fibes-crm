import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function Kbd({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <kbd className={cn('inline-grid min-w-5 place-items-center rounded-md border border-line-2 bg-card-2 px-1.5 font-sans text-2xs font-semibold text-muted shadow-[0_1px_0_var(--line-2)]', className)}>
            {children}
        </kbd>
    );
}
