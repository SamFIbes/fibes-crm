import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';

export function Tooltip({ label, children }: { label: string; children: ReactNode }) {
    return (
        <TooltipPrimitive.Provider delayDuration={300}>
            <TooltipPrimitive.Root>
                <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
                <TooltipPrimitive.Portal>
                    <TooltipPrimitive.Content sideOffset={6} className="z-50 rounded-md bg-navy px-2 py-1 text-2xs font-medium text-white shadow-pop">
                        {label}
                    </TooltipPrimitive.Content>
                </TooltipPrimitive.Portal>
            </TooltipPrimitive.Root>
        </TooltipPrimitive.Provider>
    );
}
