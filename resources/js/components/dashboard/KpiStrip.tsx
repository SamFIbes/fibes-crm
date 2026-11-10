import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { Tile } from '@/components/dashboard/types';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const KEY_TILE_COUNT = 4;
const variants = ['fd-tile--deep', 'card', 'fd-tile--mint', 'fd-tile--aqua'] as const;

export function KpiStrip({ tiles, onChooseTiles }: { tiles: Tile[]; onChooseTiles: () => void }) {
    const { t, dir } = useI18n();
    const Chevron = dir === 'rtl' ? ChevronLeft : ChevronRight;
    const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
    const keyTiles = tiles.slice(0, KEY_TILE_COUNT);
    const moreTiles = tiles.slice(KEY_TILE_COUNT);

    return (
        <section aria-labelledby="fd-kpi-heading" data-testid="dashboard-tiles" className="mt-6">
            <h2 id="fd-kpi-heading" className="sr-only">{t('dashboard.key_figures')}</h2>
            {tiles.length === 0 ? (
                <div className="card flex flex-col items-start gap-4 p-6">
                    <div>
                        <h3 className="text-base font-semibold">{t('dashboard.no_tiles_title')}</h3>
                        <p className="mt-2 text-sm text-muted">{t('dashboard.no_tiles_body')}</p>
                    </div>
                    <Button type="button" variant="outline" onClick={onChooseTiles}>{t('dashboard.edit_tiles')}</Button>
                </div>
            ) : (
                <>
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {keyTiles.map((tile, index) => (
                            <li key={tile.key} className="flex min-w-0">
                                <Link
                                    href={tile.href}
                                    data-testid={`tile-${tile.key}`}
                                    className={cn('fd-tile fd-lift fd-rise min-w-0 flex-1', variants[index])}
                                    style={{ '--i': index } as CSSProperties}
                                >
                                    <span className="fd-tile-head">
                                        <span className="fd-label">{t(`reports.key_${tile.key}`)}</span>
                                        <Chevron className="size-4 shrink-0 opacity-70" aria-hidden="true" />
                                    </span>
                                    <span className="fd-value block truncate" dir="ltr">{tile.value}</span>
                                    <span className="fd-tile-foot">
                                        <span>{t('dashboard.open_report')}</span>
                                        <Arrow className="size-4 shrink-0" aria-hidden="true" />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    {moreTiles.length > 0 ? (
                        <>
                            <h3 className="mb-2 mt-6 text-xs font-semibold text-muted">{t('dashboard.more_figures')}</h3>
                            <ul className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                {moreTiles.map((tile, offset) => (
                                    <li key={tile.key} className="flex min-w-0">
                                        <Link
                                            href={tile.href}
                                            data-testid={`tile-${tile.key}`}
                                            className="card fd-lift fd-rise flex min-h-11 min-w-0 flex-1 flex-col justify-center p-4"
                                            style={{ '--i': offset + KEY_TILE_COUNT } as CSSProperties}
                                        >
                                            <span className="fd-label">{t(`reports.key_${tile.key}`)}</span>
                                            <span className="fd-num mt-2 block truncate text-2xl font-semibold tabular" dir="ltr">{tile.value}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </>
                    ) : null}
                </>
            )}
        </section>
    );
}
