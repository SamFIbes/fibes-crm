import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { PeriodBar } from '@/components/dashboard/PeriodBar';
import { QueueList } from '@/components/dashboard/QueueList';
import { QueueLoad } from '@/components/dashboard/QueueLoad';
import { SectionGrid } from '@/components/dashboard/SectionGrid';
import type { AttentionItem, Queue, Tile } from '@/components/dashboard/types';
import { I18nProvider } from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const flat: Record<string, string> = {
    'dashboard.key_figures': 'Key figures',
    'dashboard.more_figures': 'More figures',
    'dashboard.open_report': 'Open report',
    'dashboard.no_tiles_title': 'No tiles chosen',
    'dashboard.no_tiles_body': 'Pick the tiles you want to see.',
    'dashboard.edit_tiles': 'Choose tiles',
    'dashboard.queue_load': 'Queue load',
    'dashboard.all_clear': 'All clear',
    'dashboard.all_clear_body': 'Nothing is waiting.',
    'dashboard.waiting_by_queue': 'Waiting by queue',
    'dashboard.chart_desc': 'Waiting by queue: :list',
    'dashboard.chart_item': ':queue: :count',
    'dashboard.list_separator': ', ',
    'dashboard.queue_promises': 'Promises past their date',
    'dashboard.sections': 'All sections',
    'dashboard.period': 'Period',
    'reports.from': 'From',
    'reports.to': 'To',
    'reports.apply': 'Apply',
    'nav.tickets': 'Tickets',
    'dashboard.bar_label': ':queue: :count waiting',
    'dashboard.queue_tickets_unassigned': 'Unassigned tickets',
    'dashboard.queue_tickets_breached': 'Breached tickets',
    'dashboard.queue_approvals': 'Approvals',
    'dashboard.short_tickets_unassigned': 'Unassigned',
    'dashboard.short_tickets_breached': 'Breached',
    'dashboard.short_approvals': 'Approvals',
    'dashboard.queue_clear': 'Nothing in this queue.',
    'dashboard.untitled': 'Untitled',
};

const translations = Object.entries(flat).reduce<Record<string, Record<string, string>>>((tree, [path, text]) => {
    const [group, key] = path.split('.');
    tree[group] = { ...(tree[group] ?? {}), [key]: text };

    return tree;
}, {});

function renderWith(ui: ReactElement, locale: 'en' | 'ar' = 'en', dir: 'ltr' | 'rtl' = 'ltr') {
    return render(
        <I18nProvider locale={locale} dir={dir} translations={translations}>
            {ui}
        </I18nProvider>,
    );
}

const tile = (key: string, value: string): Tile => ({ key, value, href: `/reports/${key}` });

const item = (id: number, tone: string, extra: Partial<AttentionItem> = {}): AttentionItem => ({
    id,
    title: `Item ${id}`,
    meta: 'English meta',
    meta_ar: 'ميتا عربي',
    href: `/items/${id}`,
    tone,
    ...extra,
});

const queue = (key: string, count: number, items: AttentionItem[] = []): Queue => ({
    key,
    href: `/queues/${key}`,
    count,
    items,
});

afterEach(cleanup);

describe('KpiStrip', () => {
    const six = [tile('a', '1'), tile('b', '2'), tile('c', '12.5%'), tile('d', '4'), tile('e', '5'), tile('f', '0.00')];

    it('renders the first four tiles as key tiles and the rest as compact tiles', () => {
        renderWith(<KpiStrip tiles={six} onChooseTiles={() => undefined} />);

        for (const key of ['a', 'b', 'c', 'd']) {
            expect(screen.getByTestId(`tile-${key}`).className).toContain('fd-tile');
        }

        expect(screen.getByTestId('tile-a').className).toContain('fd-tile--deep');
        expect(screen.getByTestId('tile-c').className).toContain('fd-tile--mint');
        expect(screen.getByTestId('tile-d').className).toContain('fd-tile--aqua');
        expect(screen.getByTestId('tile-e').className).not.toContain('fd-tile');
        expect(screen.getByTestId('tile-f').className).toContain('card');
        expect(screen.getByText('More figures')).toBeTruthy();
        expect(screen.getAllByText('Open report')).toHaveLength(4);
        expect(screen.getByTestId('tile-a').getAttribute('href')).toBe('/reports/a');
        expect(screen.getByText('12.5%').getAttribute('dir')).toBe('ltr');
    });

    it('hides the more-figures heading when there are four tiles or fewer', () => {
        renderWith(<KpiStrip tiles={six.slice(0, 3)} onChooseTiles={() => undefined} />);

        expect(screen.queryByText('More figures')).toBeNull();
    });

    it('shows an empty state with a choose-tiles button', () => {
        const onChoose = vi.fn();

        renderWith(<KpiStrip tiles={[]} onChooseTiles={onChoose} />);

        expect(screen.getByText('No tiles chosen')).toBeTruthy();
        expect(screen.getByText('Pick the tiles you want to see.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Choose tiles' }));

        expect(onChoose).toHaveBeenCalledTimes(1);
    });
});

describe('QueueLoad', () => {
    const queues = [queue('tickets_unassigned', 3), queue('tickets_breached', 1), queue('approvals', 0)];

    it('shows the total, a share bar segment per non-empty queue and no all-clear text', () => {
        renderWith(<QueueLoad queues={queues} />);

        expect(within(screen.getByTestId('queue-load')).getByText('4')).toBeTruthy();
        expect(screen.getByTestId('queue-share-bar').children).toHaveLength(2);
        expect(screen.queryByText('All clear')).toBeNull();
    });

    it('shows the all-clear state instead of the bar when nothing is waiting', () => {
        renderWith(<QueueLoad queues={[queue('tickets_unassigned', 0), queue('approvals', 0)]} />);

        expect(screen.getByText('All clear')).toBeTruthy();
        expect(screen.getByText('Nothing is waiting.')).toBeTruthy();
        expect(screen.queryByTestId('queue-share-bar')).toBeNull();
    });

    it('keeps the bars as a mouse shortcut only, so keyboard and screen readers get one fully named link per queue', () => {
        const { container } = renderWith(<QueueLoad queues={queues} />);
        const bars = screen.getByTestId('queue-bars');
        const links = Array.from(bars.querySelectorAll('a'));

        expect(bars.getAttribute('aria-hidden')).toBe('true');
        expect(links.map((link) => link.getAttribute('href'))).toEqual(['/queues/tickets_unassigned', '/queues/tickets_breached', '/queues/approvals']);
        expect(links.every((link) => link.getAttribute('tabindex') === '-1')).toBe(true);
        expect(links.some((link) => link.hasAttribute('aria-label'))).toBe(false);
        expect(links[0]?.getAttribute('title')).toBe('Unassigned tickets');
        expect(screen.queryAllByRole('link')).toHaveLength(0);
        expect(container.querySelector('[data-top="true"]')).not.toBeNull();
    });

    it('marks only the largest queue as the top bar', () => {
        const { container } = renderWith(<QueueLoad queues={queues} />);
        const tops = container.querySelectorAll('[data-top="true"]');

        expect(tops).toHaveLength(1);
        expect(container.querySelector('a[href="/queues/tickets_unassigned"]')?.contains(tops[0] ?? null)).toBe(true);
    });

    it('describes the chart as name and count pairs, with no plural words to get wrong', () => {
        renderWith(<QueueLoad queues={[queue('tickets_unassigned', 1), queue('approvals', 0)]} />);

        expect(screen.getByTestId('queue-chart-desc').textContent).toBe('Waiting by queue: Unassigned tickets: 1, Approvals: 0');
    });

    it('puts the caption first in the figure, holding the chart heading', () => {
        renderWith(<QueueLoad queues={queues} />);
        const figure = screen.getByTestId('queue-chart');

        expect(figure.firstElementChild?.tagName).toBe('FIGCAPTION');
        expect(figure.querySelectorAll('figcaption')).toHaveLength(1);
        expect(within(figure.firstElementChild as HTMLElement).getByRole('heading', { name: 'Waiting by queue' })).toBeTruthy();
    });

    it('falls back to the full queue name when there is no short label', () => {
        renderWith(<QueueLoad queues={[queue('promises', 2)]} />);
        const bars = screen.getByTestId('queue-bars');

        expect(bars.textContent).toContain('Promises past their date');
        expect(bars.textContent).not.toContain('dashboard.short_');
    });

    it('keeps a tiny share visible in the share bar', () => {
        renderWith(<QueueLoad queues={[queue('tickets_unassigned', 999), queue('approvals', 1)]} />);
        const segments = Array.from(screen.getByTestId('queue-share-bar').children) as HTMLElement[];

        expect(segments).toHaveLength(2);
        expect(segments[1]?.style.minWidth).toBe('6px');
    });
});

describe('PeriodBar', () => {
    it('labels the period on screen, not dates typed but not applied yet', () => {
        renderWith(<PeriodBar from="2026-01-01" to="2026-01-31" applied={{ from: '2026-11-01', to: '2026-11-10' }} onChange={() => {}} onSubmit={() => {}} />);
        const label = screen.getByTestId('period-label').textContent ?? '';

        expect(label).toContain('Nov');
        expect(label).not.toContain('Jan');
        expect((screen.getByLabelText('From') as HTMLInputElement).value).toBe('2026-01-01');
    });
});

describe('SectionGrid', () => {
    it('uses a heading that makes sense on its own', () => {
        const Icon = () => null;
        renderWith(<SectionGrid links={[{ key: 'tickets', href: '/tickets', icon: Icon }]} />);

        expect(screen.getByRole('heading', { name: 'All sections' })).toBeTruthy();
    });
});

describe('QueueList', () => {
    it('uses the warn badge when the first item is a warning', () => {
        renderWith(<QueueList queue={queue('tickets_unassigned', 2, [item(1, 'warn'), item(2, 'bad')])} total={10} />);

        const badge = screen.getByTestId('queue-badge-tickets_unassigned');

        expect(badge.getAttribute('data-tone')).toBe('warn');
        expect(badge.className).toContain('bg-warn-bg');
    });

    it('uses the bad badge when the first item is bad', () => {
        renderWith(<QueueList queue={queue('tickets_breached', 2, [item(1, 'bad')])} total={10} />);

        const badge = screen.getByTestId('queue-badge-tickets_breached');

        expect(badge.getAttribute('data-tone')).toBe('bad');
        expect(badge.className).toContain('bg-bad-bg');
    });

    it('uses the neutral badge for other tones and the good badge for an empty queue', () => {
        const { unmount } = renderWith(<QueueList queue={queue('approvals', 1, [item(1, 'info')])} total={10} />);

        expect(screen.getByTestId('queue-badge-approvals').getAttribute('data-tone')).toBe('neutral');

        unmount();
        renderWith(<QueueList queue={queue('approvals', 0)} total={10} />);

        const badge = screen.getByTestId('queue-badge-approvals');

        expect(badge.getAttribute('data-tone')).toBe('good');
        expect(badge.className).toContain('bg-good-bg');
        expect(screen.getByText('Nothing in this queue.')).toBeTruthy();
    });

    it('shows English meta with when and number', () => {
        renderWith(<QueueList queue={queue('approvals', 1, [item(1, 'warn', { when: '2h', number: 'T-1' })])} total={10} />);

        expect(screen.getByText('English meta · 2h · T-1')).toBeTruthy();
        expect(screen.queryByText(/ميتا عربي/)).toBeNull();
    });

    it('prefers meta_ar in Arabic and falls back to meta', () => {
        const { unmount } = renderWith(
            <QueueList queue={queue('approvals', 1, [item(1, 'warn', { when: '2h', number: 'T-1' })])} total={10} />,
            'ar',
            'rtl',
        );

        expect(screen.getByText('ميتا عربي · 2h · T-1')).toBeTruthy();

        unmount();
        renderWith(<QueueList queue={queue('approvals', 1, [item(1, 'warn', { meta_ar: null })])} total={10} />, 'ar', 'rtl');

        expect(screen.getByText('English meta')).toBeTruthy();
    });

    it('falls back to the untitled label and links each row', () => {
        renderWith(<QueueList queue={queue('approvals', 1, [item(7, 'warn', { title: null })])} total={10} />);

        const link = screen.getByText('Untitled').closest('a');

        expect(link?.getAttribute('href')).toBe('/items/7');
    });

    it('draws the hairline track as the queue share of everything waiting', () => {
        renderWith(<QueueList queue={queue('approvals', 4, [item(1, 'bad')])} total={10} />);

        const fill = screen.getByTestId('queue-share-approvals').firstElementChild as HTMLElement;
        expect(fill.style.getPropertyValue('--fd-scale')).toBe('0.4');
        expect(fill.className).toContain('fd-hairline__fill--bad');
        expect(screen.getByRole('link', { name: 'Approvals: 4 waiting' })).toHaveAttribute('href', '/queues/approvals');
    });
});
