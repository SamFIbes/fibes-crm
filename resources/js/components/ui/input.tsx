import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, type = 'text', ...props }, ref) => {
    return (
        <input
            ref={ref}
            type={type}
            className={cn(
                'flex h-10 w-full rounded-lg border border-input bg-card px-3 text-sm text-foreground shadow-sm transition-colors duration-200 placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
});

Input.displayName = 'Input';
