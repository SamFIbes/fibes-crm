import { Command as CommandPrimitive } from 'cmdk';
import { type ComponentPropsWithoutRef, type HTMLAttributes } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export function Command({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive>) {
    return <CommandPrimitive className={cn('flex h-full w-full flex-col overflow-hidden', className)} {...props} />;
}

export function CommandInput({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive.Input>) {
    return (
        <CommandPrimitive.Input
            className={cn(
                'h-12 w-full border-b border-border bg-transparent px-4 text-sm outline-none placeholder:text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function CommandList({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive.List>) {
    return <CommandPrimitive.List className={cn('max-h-80 overflow-y-auto p-2', className)} {...props} />;
}

export function CommandEmpty({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive.Empty>) {
    return <CommandPrimitive.Empty className={cn('px-3 py-8 text-center text-sm text-muted-foreground', className)} {...props} />;
}

export function CommandGroup({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive.Group>) {
    return <CommandPrimitive.Group className={cn('overflow-hidden text-foreground [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground', className)} {...props} />;
}

export function CommandItem({ className, ...props }: ComponentPropsWithoutRef<typeof CommandPrimitive.Item>) {
    return (
        <CommandPrimitive.Item
            className={cn(
                'flex cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-sm outline-none data-[selected=true]:bg-secondary',
                className,
            )}
            {...props}
        />
    );
}

export function CommandDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    children: HTMLAttributes<HTMLDivElement>['children'];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hideClose className="overflow-hidden p-0">
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">{description}</DialogDescription>
                <Command shouldFilter={false}>{children}</Command>
            </DialogContent>
        </Dialog>
    );
}
