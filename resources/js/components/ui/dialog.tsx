import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;
export const DialogDescription = DialogPrimitive.Description;

export const DialogContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & { hideClose?: boolean; closeLabel?: string }>(
    ({ className, children, hideClose = false, closeLabel = 'Close', ...props }, ref) => (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[#0E0F2D]/55 backdrop-blur-[2px]" />
            <DialogPrimitive.Content
                ref={ref}
                className={cn(
                    'fixed left-1/2 top-1/2 z-50 w-[min(100%-2rem,36rem)] -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-border bg-popover text-popover-foreground shadow-xl duration-200',
                    className,
                )}
                {...props}
            >
                {children}
                {hideClose ? null : (
                    <DialogPrimitive.Close className="absolute end-3 top-3 rounded-md p-1 text-muted-foreground transition-colors duration-200 hover:bg-secondary hover:text-foreground">
                        <X className="h-4 w-4" />
                        <span className="sr-only">{closeLabel}</span>
                    </DialogPrimitive.Close>
                )}
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    ),
);

DialogContent.displayName = 'DialogContent';

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-col gap-1.5', className)} {...props} />;
}
