import { cn } from '@/lib/utils';

export function Avatar({ name, size = 28, className }: { name: string; size?: number; className?: string }) {
    const initials = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <span
            aria-hidden
            className={cn('inline-grid shrink-0 place-items-center rounded-full bg-good-bg text-2xs font-bold text-good', className)}
            style={{ width: size, height: size }}
        >
            {initials || '·'}
        </span>
    );
}
