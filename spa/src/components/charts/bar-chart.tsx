"use client";

import { useEffect, useRef, useState } from "react";

import { cn } from "@/lib/utils";

export interface Series {
  key: string;
  label: string;
  /** CSS colour, normally a viz token: "var(--viz-1)". Slots are assigned in fixed order. */
  color: string;
}

export interface BarDatum {
  label: string; // x-axis tick text
  title: string; // tooltip / table heading
  values: Record<string, number>;
}

interface BarChartProps {
  data: BarDatum[];
  series: Series[];
  height?: number;
  format?: (n: number) => string;
  ariaLabel: string;
}

function niceMax(max: number): number {
  if (max <= 0) return 4;
  const pow = 10 ** Math.floor(Math.log10(max));
  const step = [1, 2, 2.5, 5, 10].find((s) => (s * pow * 4) >= max) ?? 10;
  return step * pow * 4;
}

function useWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(0);
  useEffect(() => {
    if (!ref.current) return;
    const ro = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    ro.observe(ref.current);
    return () => ro.disconnect();
  }, []);
  return [ref, width] as const;
}

/** Top-rounded bar segment (4px radius on the data end, square on the baseline side). */
function topRounded(x: number, y: number, w: number, h: number, r = 4) {
  const rr = Math.min(r, w / 2, h);
  return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}

/** Vertical bars, stacked when given several series. Hover a column for its tooltip. */
export function BarChart({ data, series, height = 240, format = (n) => n.toLocaleString("en-IN"), ariaLabel }: BarChartProps) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);

  const pad = { top: 8, right: 8, bottom: 26, left: 44 };
  const innerW = Math.max(0, width - pad.left - pad.right);
  const innerH = height - pad.top - pad.bottom;
  const totals = data.map((d) => series.reduce((sum, s) => sum + (d.values[s.key] ?? 0), 0));
  const yMax = niceMax(Math.max(0, ...totals));
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => f * yMax);
  const band = data.length ? innerW / data.length : 0;
  const barW = Math.max(4, Math.min(28, band * 0.62));
  const labelEvery = Math.max(1, Math.ceil(data.length / Math.max(1, Math.floor(innerW / 56))));
  const y = (v: number) => pad.top + innerH - (v / yMax) * innerH;

  return (
    <div className="grid gap-3">
      {series.length > 1 && (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" aria-label="Legend">
          {series.map((s) => (
            <li key={s.key} className="flex items-center gap-1.5">
              <span aria-hidden className="size-2.5 rounded-sm" style={{ background: s.color }} />
              {s.label}
            </li>
          ))}
        </ul>
      )}
      <div ref={ref} className="relative" style={{ height }} onMouseLeave={() => setHover(null)}>
        {width > 0 && (
          <svg width={width} height={height} role="img" aria-label={ariaLabel} className="block overflow-visible">
            {ticks.map((t) => (
              <g key={t}>
                <line x1={pad.left} x2={width - pad.right} y1={y(t)} y2={y(t)} stroke="var(--viz-grid)" strokeWidth={1} />
                <text x={pad.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">
                  {format(t)}
                </text>
              </g>
            ))}
            {data.map((d, i) => {
              const cx = pad.left + band * i + band / 2;
              let acc = 0;
              const segments = series
                .map((s) => ({ s, v: d.values[s.key] ?? 0 }))
                .filter(({ v }) => v > 0);
              return (
                <g key={d.title}>
                  {hover === i && <rect x={cx - band / 2} y={pad.top} width={band} height={innerH} className="fill-muted" />}
                  {segments.map(({ s, v }, j) => {
                    const top = y(acc + v);
                    const h = y(acc) - top;
                    acc += v;
                    const isTop = j === segments.length - 1;
                    // 2px surface gap between stacked segments.
                    const gap = j > 0 ? 2 : 0;
                    return isTop ? (
                      <path key={s.key} d={topRounded(cx - barW / 2, top, barW, Math.max(0, h - gap))} fill={s.color} />
                    ) : (
                      <rect key={s.key} x={cx - barW / 2} y={top} width={barW} height={Math.max(0, h - gap)} fill={s.color} />
                    );
                  })}
                  {i % labelEvery === 0 && (
                    <text x={cx} y={height - 8} textAnchor="middle" className="fill-muted-foreground text-[11px]">
                      {d.label}
                    </text>
                  )}
                  {/* Hit target: the whole column, bigger than the bar. */}
                  <rect x={cx - band / 2} y={pad.top} width={band} height={innerH} fill="transparent" onMouseEnter={() => setHover(i)} />
                </g>
              );
            })}
          </svg>
        )}
        {hover !== null && width > 0 && (
          <div
            role="tooltip"
            className="pointer-events-none absolute z-10 min-w-36 rounded-lg border bg-popover px-3 py-2 text-xs shadow-md"
            style={{
              left: Math.min(Math.max(pad.left + band * hover + band / 2 - 72, 0), width - 150),
              top: 0,
            }}
          >
            <p className="mb-1 font-medium text-foreground">{data[hover].title}</p>
            {series.map((s) => (
              <p key={s.key} className="flex items-center justify-between gap-4 text-muted-foreground">
                <span className="flex items-center gap-1.5">
                  <span aria-hidden className="size-2 rounded-sm" style={{ background: s.color }} />
                  {s.label}
                </span>
                <span className="font-medium text-foreground tabular-nums">{format(data[hover].values[s.key] ?? 0)}</span>
              </p>
            ))}
          </div>
        )}
      </div>
      <DataTable data={data} series={series} format={format} />
    </div>
  );
}

function DataTable({ data, series, format }: { data: BarDatum[]; series: Series[]; format: (n: number) => string }) {
  return (
    <details className="text-xs">
      <summary className="cursor-pointer text-muted-foreground hover:text-foreground">View as table</summary>
      <div className="mt-2 max-h-64 overflow-auto rounded-lg border">
        <table className="w-full">
          <thead className="sticky top-0 bg-muted text-left">
            <tr>
              <th className="px-3 py-1.5 font-medium" scope="col">Period</th>
              {series.map((s) => (
                <th key={s.key} className="px-3 py-1.5 text-right font-medium" scope="col">{s.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((d) => (
              <tr key={d.title} className="border-t">
                <th scope="row" className="px-3 py-1.5 text-left font-normal">{d.title}</th>
                {series.map((s) => (
                  <td key={s.key} className="px-3 py-1.5 text-right tabular-nums">{format(d.values[s.key] ?? 0)}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  );
}

/** Ranked horizontal bars with direct labels (name + value), one series. */
export function BarList({ items, format = (n) => n.toLocaleString("en-IN"), color = "var(--viz-1)" }: {
  items: { key: string | number; label: string; value: number; detail?: string }[];
  format?: (n: number) => string;
  color?: string;
}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="grid gap-2.5">
      {items.map((item) => (
        <li key={item.key} className="grid grid-cols-[minmax(6rem,9rem)_1fr_auto] items-center gap-3 text-sm" title={item.detail}>
          <span className="truncate text-muted-foreground">{item.label}</span>
          <span className="h-2.5 rounded-full bg-muted">
            <span
              className={cn("block h-full rounded-full", item.value === 0 && "hidden")}
              style={{ width: `${(item.value / max) * 100}%`, background: color }}
            />
          </span>
          <span className="w-10 text-right font-medium tabular-nums">{format(item.value)}</span>
        </li>
      ))}
    </ul>
  );
}
