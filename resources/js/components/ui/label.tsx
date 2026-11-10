import * as LabelPrimitive from '@radix-ui/react-label';
import { forwardRef, type ComponentPropsWithoutRef } from 'react';
import { cn } from '@/lib/utils';

export const Label = forwardRef<HTMLLabelElement, ComponentPropsWithoutRef<typeof LabelPrimitive.Root>>(
    ({ className, ...props }, ref) => (
        <LabelPrimitive.Root ref={ref} className={cn('text-[12.5px] font-semibold text-muted', className)} {...props} />
    ),
);

Label.displayName = 'Label';
