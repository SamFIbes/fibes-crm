import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';

export function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint ? <p className="text-xs leading-5 text-muted-foreground">{hint}</p> : null}
            {error ? (
                <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
