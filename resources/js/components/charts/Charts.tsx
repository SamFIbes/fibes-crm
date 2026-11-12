import { type ReactNode, useId, useState } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

// ---------------------------------------------------------------------------
// Helper: niceMax
// ---------------------------------------------------------------------------
export function niceMax(value: number): number {
  if (value <= 0) return 10;
  const padded = value * 1.15;
  const exp = Math.floor(Math.log10(padded));
  const pow = Math.pow(10, exp);
  const f = padded / pow;
  let nice: number;
  if (f <= 1) nice = 1;
  else if (f <= 2) nice = 2;
  else if (f <= 5) nice = 5;
  else nice = 10;
  return nice * pow;
}

// ---------------------------------------------------------------------------
// ChartCard
// ---------------------------------------------------------------------------
interface ChartCardProps {
  title: string;
  description?: string;
  table: { columns: string[]; rows: (string | number)[][] };
  children: ReactNode;
  toggleLabels: { show: string; hide: string };
}

export function ChartCard({ title, description, table, children, toggleLabels }: ChartCardProps) {
  const [showTable, setShowTable] = useState(false);
  const tableId = useId();
  const visibleTableId = `${tableId}-visible`;
  const hiddenTableId = `${tableId}-hidden`;

  const tableMarkup = (id: string, hidden: boolean) => (
    <table id={id} className={cn(hidden ? 'sr-only' : 'w-full text-2xs border-collapse mt-2')}>
      <caption className="sr-only">{title}</caption>
      <thead>
        <tr>
          {table.columns.map((col, i) => (
            <th key={i} scope="col" className={cn(!hidden && 'text-start border-b border-line px-2 py-1 font-semibold text-muted')}>
              {col}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {table.rows.map((row, ri) => (
          <tr key={ri}>
            {row.map((cell, ci) =>
              ci === 0 ? (
                <th key={ci} scope="row" className={cn(!hidden && 'text-start border-b border-line px-2 py-1 font-medium')}>
                  {cell}
                </th>
              ) : (
                <td key={ci} className={cn(!hidden && 'text-end border-b border-line px-2 py-1')}>
                  {cell}
                </td>
              )
            )}
          </tr>
        ))}
      </tbody>
    </table>
  );

  return (
    <figure className="card p-4 min-w-0">
      <figcaption className="flex items-start justify-between gap-2 flex-wrap">
        <div>
          <h3 className="text-sm font-semibold text-text">{title}</h3>
          {description && <p className="text-2xs text-muted mt-1">{description}</p>}
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-pressed={showTable}
          aria-controls={showTable ? visibleTableId : hiddenTableId}
          onClick={() => setShowTable(v => !v)}
        >
          {showTable ? toggleLabels.hide : toggleLabels.show}
        </Button>
      </figcaption>

      <div className="mt-4">
        {showTable ? (
          <>
            {tableMarkup(visibleTableId, false)}
            {/* sr-only duplicate for screen readers is not needed when visible table is shown */}
          </>
        ) : (
          <>
            {children}
            {tableMarkup(hiddenTableId, true)}
          </>
        )}
      </div>
    </figure>
  );
}

// ---------------------------------------------------------------------------
// Shared axis helpers
// ---------------------------------------------------------------------------
const VIEWBOX_W = 640;
const VIEWBOX_H = 240;
const MARGIN = { top: 16, right: 16, bottom: 40, left: 52 };
const PLOT_W = VIEWBOX_W - MARGIN.left - MARGIN.right;
const PLOT_H = VIEWBOX_H - MARGIN.top - MARGIN.bottom;
const GRID_LINES = 2;

// Tailwind only builds classes it can see written out, so every colour is listed literally here.
const TONES: Record<string, { fill: string; stroke: string; bg: string }> = {
  ink: { fill: 'fill-text', stroke: 'stroke-text', bg: 'bg-text' },
  navy: { fill: 'fill-navy', stroke: 'stroke-navy', bg: 'bg-navy' },
  mint: { fill: 'fill-mint', stroke: 'stroke-mint', bg: 'bg-mint' },
  aqua: { fill: 'fill-aqua', stroke: 'stroke-aqua', bg: 'bg-aqua' },
  sky: { fill: 'fill-sky', stroke: 'stroke-sky', bg: 'bg-sky' },
  accent: { fill: 'fill-accent', stroke: 'stroke-accent', bg: 'bg-accent' },
  warn: { fill: 'fill-warn', stroke: 'stroke-warn', bg: 'bg-warn' },
  good: { fill: 'fill-good', stroke: 'stroke-good', bg: 'bg-good' },
  bad: { fill: 'fill-bad', stroke: 'stroke-bad', bg: 'bg-bad' },
};

function tone(className: string): { fill: string; stroke: string; bg: string } {
  const name = className.replace(/^(fill|stroke|bg)-/, '');

  return TONES[name] ?? TONES.mint;
}

function fillToStroke(className: string): string {
  return tone(className).stroke;
}

function fillToBg(className: string): string {
  return tone(className).bg;
}

function strokeToFill(className: string): string {
  return tone(className).fill;
}

interface LegendProps {
  series: { name: string; className: string }[];
}

function Legend({ series }: LegendProps) {
  if (series.length <= 1) return null;
  return (
    <ul className="flex flex-wrap gap-x-4 gap-y-1 mt-2 justify-center" role="list">
      {series.map((s, i) => (
        <li key={i} className="flex items-center gap-1 text-2xs text-muted">
          <span className={cn('inline-block w-3 h-3 rounded-sm flex-shrink-0', fillToBg(s.className))} aria-hidden="true" />
          {s.name}
        </li>
      ))}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// ColumnChart
// ---------------------------------------------------------------------------
interface ChartData {
  label: string;
  values: number[];
}

interface SeriesDef {
  name: string;
  className: string;
}

interface ColumnChartProps {
  label: string;
  data: ChartData[];
  series: SeriesDef[];
  formatValue?: (v: number) => string;
}

export function ColumnChart({ label, data, series, formatValue = String }: ColumnChartProps) {
  if (data.length === 0) return null;

  const allValues = data.flatMap(d => d.values);
  const maxVal = allValues.length > 0 ? Math.max(...allValues) : 0;
  const yMax = niceMax(maxVal);

  const gridValues = Array.from({ length: GRID_LINES }, (_, i) =>
    Math.round((yMax / GRID_LINES) * (i + 1))
  );

  const n = data.length;
  const groupW = PLOT_W / n;
  const seriesCount = series.length;
  const barPad = groupW * 0.15;
  const barW = Math.max(2, (groupW - barPad * 2) / seriesCount - 2);
  const showAllLabels = n <= 8;

  return (
    <div>
      <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={label}>
      <svg
        direction="ltr"
        role="img"
        aria-label={label}
        viewBox={`0 0 ${VIEWBOX_W} ${VIEWBOX_H}`}
        className="h-auto w-full min-w-[480px]"
        xmlns="http://www.w3.org/2000/svg"
      >
        {/* Gridlines */}
        {gridValues.map((gv, i) => {
          const y = MARGIN.top + PLOT_H - (gv / yMax) * PLOT_H;
          return (
            <g key={i}>
              <line
                x1={MARGIN.left}
                x2={MARGIN.left + PLOT_W}
                y1={y}
                y2={y}
                className="stroke-line"
                strokeWidth={1}
              />
              <text
                x={MARGIN.left - 6}
                y={y}
                textAnchor="end"
                dominantBaseline="middle"
                className="fill-muted text-2xs"
                fontSize={12}
              >
                {formatValue(gv)}
              </text>
            </g>
          );
        })}

        {/* Baseline */}
        <line
          x1={MARGIN.left}
          x2={MARGIN.left + PLOT_W}
          y1={MARGIN.top + PLOT_H}
          y2={MARGIN.top + PLOT_H}
          className="stroke-line"
          strokeWidth={1}
        />

        {/* Bars */}
        {data.map((d, di) => {
          const groupX = MARGIN.left + di * groupW + barPad;
          const showLabel = showAllLabels || di % 2 === 0;
          return (
            <g key={di}>
              {d.values.map((v, si) => {
                const barH = yMax > 0 ? (v / yMax) * PLOT_H : 0;
                const x = groupX + si * (barW + 2);
                const y = MARGIN.top + PLOT_H - barH;
                const seriesDef = series[si];
                return (
                  <rect
                    key={si}
                    x={x}
                    y={y}
                    width={barW}
                    height={Math.max(0, barH)}
                    rx={2}
                    className={strokeToFill(seriesDef?.className ?? 'fill-mint')}
                  >
                    <title>{`${d.label} – ${seriesDef?.name ?? ''}: ${formatValue(v)}`}</title>
                  </rect>
                );
              })}
              {showLabel && (
                <text
                  x={groupX + (groupW - barPad * 2) / 2}
                  y={MARGIN.top + PLOT_H + 14}
                  textAnchor="middle"
                  className="fill-muted"
                  fontSize={12}
                >
                  {d.label}
                </text>
              )}
            </g>
          );
        })}
      </svg>
      </div>
      <Legend series={series} />
    </div>
  );
}

// ---------------------------------------------------------------------------
// LineChart
// ---------------------------------------------------------------------------
interface LineChartProps {
  label: string;
  data: ChartData[];
  series: SeriesDef[];
  formatValue?: (v: number) => string;
}

export function LineChart({ label, data, series, formatValue = String }: LineChartProps) {
  if (data.length === 0) return null;

  const allValues = data.flatMap(d => d.values);
  const maxVal = allValues.length > 0 ? Math.max(...allValues) : 0;
  const yMax = niceMax(maxVal);

  const gridValues = Array.from({ length: GRID_LINES }, (_, i) =>
    Math.round((yMax / GRID_LINES) * (i + 1))
  );

  const n = data.length;
  const showAllLabels = n <= 8;

  function xPos(i: number): number {
    if (n <= 1) return MARGIN.left + PLOT_W / 2;
    return MARGIN.left + (i / (n - 1)) * PLOT_W;
  }

  function yPos(v: number): number {
    return MARGIN.top + PLOT_H - (yMax > 0 ? (v / yMax) * PLOT_H : 0);
  }

  return (
    <div>
      <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={label}>
      <svg
        direction="ltr"
        role="img"
        aria-label={label}
        viewBox={`0 0 ${VIEWBOX_W} ${VIEWBOX_H}`}
        className="h-auto w-full min-w-[480px]"
        xmlns="http://www.w3.org/2000/svg"
      >
        {/* Gridlines */}
        {gridValues.map((gv, i) => {
          const y = yPos(gv);
          return (
            <g key={i}>
              <line
                x1={MARGIN.left}
                x2={MARGIN.left + PLOT_W}
                y1={y}
                y2={y}
                className="stroke-line"
                strokeWidth={1}
              />
              <text
                x={MARGIN.left - 6}
                y={y}
                textAnchor="end"
                dominantBaseline="middle"
                className="fill-muted"
                fontSize={12}
              >
                {formatValue(gv)}
              </text>
            </g>
          );
        })}

        {/* Baseline */}
        <line
          x1={MARGIN.left}
          x2={MARGIN.left + PLOT_W}
          y1={MARGIN.top + PLOT_H}
          y2={MARGIN.top + PLOT_H}
          className="stroke-line"
          strokeWidth={1}
        />

        {/* Series */}
        {series.map((s, si) => {
          const points = data.map((d, di) => ({
            x: xPos(di),
            y: yPos(d.values[si] ?? 0),
            v: d.values[si] ?? 0,
            label: d.label,
          }));
          const pointsStr = points.map(p => `${p.x},${p.y}`).join(' ');

          // Area for series 0
          const areaPath =
            si === 0 && points.length > 0
              ? `M ${points[0].x},${MARGIN.top + PLOT_H} ` +
                points.map(p => `L ${p.x},${p.y}`).join(' ') +
                ` L ${points[points.length - 1].x},${MARGIN.top + PLOT_H} Z`
              : null;

          const strokeClass = fillToStroke(s.className);
          const fillClass = strokeToFill(s.className);

          return (
            <g key={si}>
              {areaPath && (
                <path
                  d={areaPath}
                  className={fillClass}
                  fillOpacity={0.2}
                  stroke="none"
                />
              )}
              <polyline
                points={pointsStr}
                fill="none"
                className={strokeClass}
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
              />
              {points.map((p, pi) => (
                <circle key={pi} cx={p.x} cy={p.y} r={3.5} className={fillClass}>
                  <title>{`${p.label} – ${s.name}: ${formatValue(p.v)}`}</title>
                </circle>
              ))}
            </g>
          );
        })}

        {/* X labels */}
        {data.map((d, di) => {
          const show = showAllLabels || di % 2 === 0;
          if (!show) return null;
          return (
            <text
              key={di}
              x={xPos(di)}
              y={MARGIN.top + PLOT_H + 14}
              textAnchor="middle"
              className="fill-muted"
              fontSize={12}
            >
              {d.label}
            </text>
          );
        })}
      </svg>
      </div>
      <Legend series={series} />
    </div>
  );
}

// ---------------------------------------------------------------------------
// DonutChart
// ---------------------------------------------------------------------------
interface DonutDatum {
  label: string;
  value: number;
  className?: string;
}

interface DonutChartProps {
  label: string;
  data: DonutDatum[];
  totalLabel: string;
  formatValue?: (v: number) => string;
}

const DONUT_PALETTE = [
  'stroke-text',
  'stroke-mint',
  'stroke-aqua',
  'stroke-sky',
  'stroke-accent',
  'stroke-warn',
  'stroke-good',
  'stroke-bad',
];

export function DonutChart({ label, data, totalLabel, formatValue = String }: DonutChartProps) {
  const CX = 100;
  const CY = 100;
  const R = 80;
  const SW = 28;
  const CIRCUMFERENCE = 2 * Math.PI * R;

  const total = data.reduce((acc, d) => acc + d.value, 0);

  function getStrokeClass(item: DonutDatum, index: number): string {
    if (item.className) {
      if (item.className.startsWith('fill-')) return fillToStroke(item.className);
      if (item.className.startsWith('stroke-')) return item.className;
    }
    return DONUT_PALETTE[index % DONUT_PALETTE.length];
  }

  let cumulativeOffset = 0;

  return (
    <div className="flex flex-col sm:flex-row items-center gap-4" role="group" aria-label={label}>
      <svg
        direction="ltr"
        viewBox="0 0 200 200"
        className="h-auto w-40 flex-shrink-0"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
      >
        {/* Background ring */}
        <circle
          cx={CX}
          cy={CY}
          r={R}
          fill="none"
          className="stroke-line"
          strokeWidth={SW}
        />

        {total > 0 ? (
          data.map((item, i) => {
            const pct = item.value / total;
            const dash = pct * CIRCUMFERENCE;
            const gap = CIRCUMFERENCE - dash;
            const offset = CIRCUMFERENCE - cumulativeOffset * CIRCUMFERENCE / total;
            cumulativeOffset += item.value;
            const strokeClass = getStrokeClass(item, i);
            const pctStr = ((item.value / total) * 100).toFixed(1);

            return (
              <circle
                key={i}
                cx={CX}
                cy={CY}
                r={R}
                fill="none"
                className={strokeClass}
                strokeWidth={SW}
                strokeDasharray={`${dash} ${gap}`}
                strokeDashoffset={offset}
                transform={`rotate(-90 ${CX} ${CY})`}
              >
                <title>{`${item.label}: ${formatValue(item.value)} (${pctStr}%)`}</title>
              </circle>
            );
          })
        ) : null}

        {/* Center total */}
        {total > 0 && (
          <>
            <text
              x={CX}
              y={CY - 6}
              textAnchor="middle"
              dominantBaseline="middle"
              className="fill-muted"
              fontSize={12}
            >
              {totalLabel}
            </text>
            <text
              x={CX}
              y={CY + 10}
              textAnchor="middle"
              dominantBaseline="middle"
              className="fill-text"
              fontSize={14}
              fontWeight="600"
            >
              {formatValue(total)}
            </text>
          </>
        )}
      </svg>

      {/* Legend */}
      <ul className="flex flex-col gap-1.5 text-2xs min-w-0 w-full" role="list">
        {data.map((item, i) => {
          const strokeClass = getStrokeClass(item, i);
          const bgClass = fillToBg(strokeToFill(strokeClass));
          const pct = total > 0 ? ((item.value / total) * 100).toFixed(1) : '0.0';
          return (
            <li key={i} className="flex items-center gap-2 min-w-0">
              <span
                className={cn('inline-block w-2.5 h-2.5 rounded-full flex-shrink-0', bgClass)}
                aria-hidden="true"
              />
              <span className="truncate text-muted flex-1 min-w-0" title={item.label}>
                {item.label}
              </span>
              <span className="text-text font-medium flex-shrink-0">{formatValue(item.value)}</span>
              <span className="text-muted flex-shrink-0 tabular-nums">{pct}%</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

// ---------------------------------------------------------------------------
// HBarChart
// ---------------------------------------------------------------------------
interface HBarDatum {
  label: string;
  value: number;
}

interface HBarChartProps {
  label: string;
  data: HBarDatum[];
  formatValue?: (v: number) => string;
}

export function HBarChart({ label, data, formatValue = String }: HBarChartProps) {
  const values = data.map(d => d.value);
  const maxVal = values.length > 0 ? Math.max(...values) : 0;

  return (
    <ul className="flex flex-col gap-2 text-2xs" role="list" aria-label={label}>
      {data.map((item, i) => {
        const pct = maxVal > 0 ? (item.value / maxVal) * 100 : 0;
        return (
          <li key={i} className="flex items-center gap-2 min-w-0">
            <span
              className="truncate text-muted flex-shrink-0 w-28 text-start"
              title={item.label}
            >
              {item.label}
            </span>
            <span className="flex-1 min-w-0">
              <div
                className="h-2 rounded bg-mint"
                style={{ width: `${pct}%` }}
                aria-hidden="true"
              />
            </span>
            <span className="text-text font-medium flex-shrink-0 text-end tabular-nums w-14">
              {formatValue(item.value)}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// ChartEmpty
// ---------------------------------------------------------------------------
interface ChartEmptyProps {
  message: string;
}

export function ChartEmpty({ message }: ChartEmptyProps) {
  return (
    <div
      role="status"
      className="flex min-h-40 items-center justify-center rounded-md bg-well p-4 text-sm text-muted"
    >
      {message}
    </div>
  );
}

// ---------------------------------------------------------------------------
// ChartSkeleton
// ---------------------------------------------------------------------------
export function ChartSkeleton() {
  return (
    <div
      aria-hidden="true"
      className="animate-pulse h-56 rounded-md bg-well"
    />
  );
}
