import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const current = options.find((option) => option.value === value) ?? options[0];

    useEffect(() => {
        const onPointer = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        window.addEventListener('mousedown', onPointer);

        return () => window.removeEventListener('mousedown', onPointer);
    }, []);

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                className="inline-flex h-10 items-center gap-2 rounded-ctl border border-line bg-card px-3 text-sm shadow-card"
                onClick={() => setOpen((currentOpen) => !currentOpen)}
            >
                <span className="text-faint">{label}</span>
                <span className="font-semibold">{current?.label}</span>
            </button>
            {open ? (
                <ul role="listbox" className="absolute z-30 mt-1 max-h-64 min-w-52 overflow-auto rounded-tile border border-line bg-card p-1 shadow-pop">
                    {options.map((option) => (
                        <li key={option.value || 'any'}>
                            <button
                                type="button"
                                role="option"
                                aria-selected={option.value === value}
                                className={cn('w-full rounded-lg px-2 py-1.5 text-start text-sm hover:bg-well', option.value === value && 'bg-well font-semibold')}
                                onClick={() => {
                                    onChange(option.value);
                                    setOpen(false);
                                }}
                            >
                                {option.label}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
