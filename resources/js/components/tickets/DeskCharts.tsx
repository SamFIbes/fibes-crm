import { useId } from 'react';

type VolumePoint = { date: string; count: number };
type ResponsePoint = { date: string; avg_minutes: number | null; responded: number };

function labelDate(date: string): string {
    const parts = date.split('-');

    return parts.length === 3 ? `${parts[1]}-${parts[2]}` : date;
}

function niceMax(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const padded = value * 1.15;
    const pow = 10 ** Math.floor(Math.log10(padded));
    const steps = [1, 2, 5, 10];
    const base = padded / pow;
    const step = steps.find((item) => base <= item) ?? 10;

    return step * pow;
}

export function VolumeChart({ data, label, unit }: { data: VolumePoint[]; label: string; unit: string }) {
    const id = useId();
    const width = 640;
    const height = 220;
    const pad = { top: 16, right: 8, bottom: 28, left: 32 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;
    const max = niceMax(Math.max(0, ...data.map((point) => point.count)));
    const barW = data.length > 0 ? (innerW / data.length) * 0.62 : 0;
    const gap = data.length > 0 ? innerW / data.length : 0;

    return (
        <figure className="min-w-0">
            <figcaption className="mb-3 text-sm font-semibold text-text">{label}</figcaption>
            <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby={`${id}-title ${id}-desc`} className="h-auto w-full">
                <title id={`${id}-title`}>{label}</title>
                <desc id={`${id}-desc`}>
                    {data.map((point) => `${point.date}: ${point.count} ${unit}`).join('. ')}
                </desc>
                {[0, 0.5, 1].map((tick) => {
                    const y = pad.top + innerH - tick * innerH;

                    return (
                        <g key={tick}>
                            <line x1={pad.left} x2={width - pad.right} y1={y} y2={y} className="stroke-line" strokeWidth="1" />
                            <text x={pad.left - 8} y={y + 3} textAnchor="end" className="fill-muted text-2xs">
                                {Math.round(max * tick)}
                            </text>
                        </g>
                    );
                })}
                {data.map((point, index) => {
                    const h = max === 0 ? 0 : (point.count / max) * innerH;
                    const x = pad.left + index * gap + (gap - barW) / 2;
                    const y = pad.top + innerH - h;

                    return (
                        <g key={point.date}>
                            <rect x={x} y={y} width={barW} height={Math.max(h, 0)} rx="4" className="fill-mint" />
                            {index % 2 === 0 || data.length <= 8 ? (
                                <text x={x + barW / 2} y={height - 8} textAnchor="middle" className="fill-muted text-2xs">
                                    {labelDate(point.date)}
                                </text>
                            ) : null}
                        </g>
                    );
                })}
            </svg>
            <table className="sr-only">
                <caption>{label}</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">{unit}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((point) => (
                        <tr key={point.date}>
                            <th scope="row">{point.date}</th>
                            <td>{point.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

export function ResponseChart({ data, label, unit }: { data: ResponsePoint[]; label: string; unit: string }) {
    const id = useId();
    const width = 640;
    const height = 220;
    const pad = { top: 16, right: 8, bottom: 28, left: 36 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;
    const values = data.map((point) => point.avg_minutes).filter((value): value is number => value !== null);
    const max = niceMax(Math.max(0, ...values));
    const step = data.length > 1 ? innerW / (data.length - 1) : innerW;
    const points = data.map((point, index) => {
        const x = pad.left + index * (data.length > 1 ? step : 0);
        const y = point.avg_minutes === null ? null : pad.top + innerH - (point.avg_minutes / max) * innerH;

        return { ...point, x, y };
    });
    const path = points
        .filter((point) => point.y !== null)
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`)
        .join(' ');
    const first = points.find((point) => point.y !== null);
    const last = [...points].reverse().find((point) => point.y !== null);
    const area = first && last && first.y !== null && last.y !== null
        ? `${path} L ${last.x} ${pad.top + innerH} L ${first.x} ${pad.top + innerH} Z`
        : '';

    return (
        <figure className="min-w-0">
            <figcaption className="mb-3 text-sm font-semibold text-text">{label}</figcaption>
            <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby={`${id}-title ${id}-desc`} className="h-auto w-full">
                <title id={`${id}-title`}>{label}</title>
                <desc id={`${id}-desc`}>
                    {data.map((point) => `${point.date}: ${point.avg_minutes ?? '—'} ${unit}`).join('. ')}
                </desc>
                {[0, 0.5, 1].map((tick) => {
                    const y = pad.top + innerH - tick * innerH;

                    return (
                        <g key={tick}>
                            <line x1={pad.left} x2={width - pad.right} y1={y} y2={y} className="stroke-line" strokeWidth="1" />
                            <text x={pad.left - 8} y={y + 3} textAnchor="end" className="fill-muted text-2xs">
                                {Math.round(max * tick)}
                            </text>
                        </g>
                    );
                })}
                {area ? <path d={area} className="fill-mint/25" /> : null}
                {path ? <path d={path} className="fill-none stroke-navy" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" /> : null}
                {points.map((point) => (
                    point.y === null ? null : (
                        <circle key={point.date} cx={point.x} cy={point.y} r="3.5" className="fill-navy" />
                    )
                ))}
                {data.map((point, index) => (
                    index % 2 === 0 || data.length <= 8 ? (
                        <text key={point.date} x={pad.left + index * (data.length > 1 ? step : 0)} y={height - 8} textAnchor="middle" className="fill-muted text-2xs">
                            {labelDate(point.date)}
                        </text>
                    ) : null
                ))}
            </svg>
            <table className="sr-only">
                <caption>{label}</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">{unit}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((point) => (
                        <tr key={point.date}>
                            <th scope="row">{point.date}</th>
                            <td>{point.avg_minutes ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
