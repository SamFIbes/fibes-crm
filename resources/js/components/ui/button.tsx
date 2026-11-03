import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap font-semibold select-none transition-[transform,background-color,box-shadow,color,border-color] duration-200 ease-[var(--ease-out)] active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-accent text-on-accent shadow-[0_10px_22px_-12px_color-mix(in_srgb,var(--accent)_80%,transparent)] hover:brightness-110 dark:hover:brightness-95',
                primary: 'bg-accent text-on-accent shadow-[0_10px_22px_-12px_color-mix(in_srgb,var(--accent)_80%,transparent)] hover:brightness-110 dark:hover:brightness-95',
                gradient: 'bg-brand-gradient text-on-accent shadow-card hover:brightness-110',
                secondary: 'bg-card text-text border border-line-2 shadow-card hover:bg-card-2',
                outline: 'bg-card text-text border border-line-2 shadow-card hover:bg-card-2',
                ghost: 'text-muted hover:bg-well hover:text-text',
                destructive: 'bg-bad text-white hover:brightness-110 dark:text-[#06201c]',
                danger: 'bg-bad text-white hover:brightness-110 dark:text-[#06201c]',
                soft: 'bg-[color-mix(in_srgb,var(--accent)_15%,transparent)] text-accent hover:bg-[color-mix(in_srgb,var(--accent)_24%,transparent)]',
                wa: 'bg-wa text-white hover:brightness-105 dark:text-[#052812]',
            },
            size: {
                default: 'h-[42px] px-[18px] text-sm rounded-full',
                sm: 'h-[34px] px-3.5 text-xs rounded-full',
                md: 'h-9 px-4 text-md rounded-full',
                lg: 'h-12 px-5 text-base rounded-full',
                icon: 'size-[42px] rounded-full',
                'icon-sm': 'size-8 rounded-full',
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
