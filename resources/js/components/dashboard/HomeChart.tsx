import { useId } from 'react';

export function metricNumber(value: string): number | null {
    const match = value.replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);

    if (!match) {
        return null;
    }

    const number = Number(match[0]);

    return Number.isFinite(number) ? number : null;
}

export function HomeChart({ points }: { points: { label: string; value: number }[] }) {
    const gradientId = useId().replace(/:/g, '');

    if (points.length === 0) {
        return null;
    }

    const max = Math.max(...points.map((point) => Math.log10(Math.abs(point.value) + 1)), 1);
    const width = 640;
    const height = 188;
    const slot = width / points.length;
    const barWidth = Math.min(56, slot * 0.46);

    return (
        <div data-testid="dashboard-chart">
            <svg viewBox={`0 0 ${width} ${height}`} className="h-48 w-full" role="img" aria-hidden>
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#78d0e0" />
                        <stop offset="48%" stopColor="#75cdc8" />
                        <stop offset="100%" stopColor="#66c48f" />
                    </linearGradient>
                </defs>
                {[0.25, 0.5, 0.75].map((step) => {
                    const y = 12 + (height - 28) * (1 - step);

                    return <line key={step} x1="0" x2={width} y1={y} y2={y} stroke="currentColor" className="text-line" strokeDasharray="3 7" />;
                })}
                {points.map((point, index) => {
                    const bar = Math.max(8, (Math.log10(Math.abs(point.value) + 1) / max) * (height - 36));
                    const x = index * slot + (slot - barWidth) / 2;
                    const y = height - 12 - bar;

                    return <rect key={point.label} x={x} y={y} width={barWidth} height={bar} rx="12" fill={`url(#${gradientId})`} />;
                })}
            </svg>
            <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                {points.map((point) => (
                    <li key={point.label} className="text-xs text-muted">
                        <span className="font-semibold text-text tabular-nums" dir="ltr">
                            {point.value}
                        </span>{' '}
                        {point.label}
                    </li>
                ))}
            </ul>
        </div>
    );
}
