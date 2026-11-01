import { motion, useReducedMotion } from 'framer-motion';
import { useId, type ReactNode } from 'react';
import { spring } from '@/lib/motion';
import { cn } from '@/lib/utils';

export function Segmented<T extends string>({
    options,
    value,
    onChange,
    className,
    size = 'md',
}: {
    options: { value: T; label: ReactNode; count?: number }[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
    size?: 'sm' | 'md';
}) {
    const id = useId();
    const reduce = useReducedMotion();

    return (
        <div role="tablist" className={cn('inline-flex rounded-[12px] border border-line bg-well p-0.5', className)}>
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(option.value)}
                        className={cn('relative rounded-[10px] font-semibold transition-colors duration-200', size === 'sm' ? 'h-7 px-2.5 text-xs' : 'h-8 px-3 text-sm', active ? 'text-text' : 'text-muted hover:text-text')}
                    >
                        {active && !reduce ? <motion.span layoutId={`seg-${id}`} transition={spring} className="absolute inset-0 rounded-[10px] bg-card shadow-card ring-1 ring-line" /> : null}
                        {active && reduce ? <span className="absolute inset-0 rounded-[10px] bg-card shadow-card ring-1 ring-line" /> : null}
                        <span className="relative inline-flex items-center gap-1.5">
                            {option.label}
                            {option.count !== undefined ? <span className="tabular rounded-full bg-well px-1.5 text-2xs text-muted">{option.count}</span> : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
