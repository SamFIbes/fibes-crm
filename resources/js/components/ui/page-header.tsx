import { motion, useReducedMotion } from 'framer-motion';
import type { ReactNode } from 'react';
import { easeOut } from '@/lib/motion';

export function PageHeader({ title, sub, actions, crumbs }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode; crumbs?: ReactNode }) {
    const reduce = useReducedMotion();

    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                {crumbs ? <div className="mb-2 flex items-center gap-1.5 text-sm text-muted">{crumbs}</div> : null}
                <motion.h1 initial={reduce ? false : { opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.5, ease: easeOut }} className="text-[30px] font-semibold leading-tight tracking-[-0.025em] sm:text-display">
                    {title}
                </motion.h1>
                {sub ? (
                    <motion.p initial={reduce ? false : { opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: reduce ? 0 : 0.12, duration: 0.5 }} className="mt-1.5 max-w-2xl text-base text-muted">
                        {sub}
                    </motion.p>
                ) : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}
