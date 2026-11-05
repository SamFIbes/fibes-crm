import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap font-semibold select-none transition-[transform,background-color,box-shadow,color,border-color] duration-200 ease-[var(--ease-out)] active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-accent text-on-accent shadow-[0_8px_20px_-10px_rgb(102_196_143/0.8)] hover:brightness-105',
                primary: 'bg-accent text-on-accent shadow-[0_8px_20px_-10px_rgb(102_196_143/0.8)] hover:brightness-105',
                gradient: 'bg-brand-gradient text-navy shadow-[0_10px_30px_-12px_rgb(117_205_200/0.9)] hover:brightness-105',
                secondary: 'border border-[var(--glass-border)] bg-[var(--glass)] text-text shadow-[var(--shadow-glass)] backdrop-blur-md hover:border-sky/40',
                outline: 'border border-[var(--glass-border)] bg-[var(--glass)] text-text shadow-[var(--shadow-glass)] backdrop-blur-md hover:border-sky/40',
                ghost: 'text-muted hover:bg-well hover:text-text',
                destructive: 'bg-bad text-white hover:brightness-110 dark:text-navy',
                danger: 'bg-bad text-white hover:brightness-110 dark:text-navy',
                soft: 'bg-well text-text hover:bg-line',
            },
            size: {
                default: 'h-10 px-4 text-sm rounded-ctl',
                sm: 'h-8 px-3 text-sm rounded-[10px]',
                md: 'h-9 px-4 text-md rounded-ctl',
                lg: 'h-11 px-5 text-base rounded-[13px]',
                icon: 'size-10 rounded-ctl',
                'icon-sm': 'size-8 rounded-[10px]',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({ className, variant, size, asChild = false, type = 'button', ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    const typeProps = asChild ? {} : { type };

    return <Comp ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...typeProps} {...props} />;
});

Button.displayName = 'Button';

export { buttonVariants };
