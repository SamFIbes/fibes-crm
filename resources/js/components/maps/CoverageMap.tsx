import { circleMarker, map as createMap, tileLayer } from 'leaflet';
import { useEffect, useRef } from 'react';
import 'leaflet/dist/leaflet.css';

export type MapPin = {
    id: string | number;
    lat: number;
    lng: number;
    label: string;
    order?: number | null;
    tone?: 'place' | 'tech' | 'job';
};

export function CoverageMap({
    tileUrl,
    pins,
    height = 420,
    testId = 'coverage-map',
}: {
    tileUrl: string;
    pins: MapPin[];
    height?: number;
    testId?: string;
}) {
    const host = useRef<HTMLDivElement>(null);
    const signature = JSON.stringify(pins.map((pin) => [pin.id, pin.lat, pin.lng, pin.order, pin.tone, pin.label]));

    useEffect(() => {
        const node = host.current;

        if (!node) {
            return;
        }

        const first = pins[0];
        const view = createMap(node, { scrollWheelZoom: false }).setView(first ? [first.lat, first.lng] : [33.8938, 35.5018], first ? 14 : 12);
        tileLayer(tileUrl, {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(view);

        pins.forEach((pin) => {
            const marker = circleMarker([pin.lat, pin.lng], {
                radius: pin.order ? 13 : 8,
                color: '#0E0F2D',
                weight: 2,
                fillColor: pin.tone === 'tech' ? '#78D0E0' : pin.tone === 'job' ? '#75CDC8' : '#66C48F',
                fillOpacity: 0.95,
            }).addTo(view);
            marker.bindTooltip(pin.order ? `${pin.order}. ${pin.label}` : pin.label, {
                permanent: Boolean(pin.order),
                direction: 'top',
                offset: [0, -8],
            });
        });

        const frame = window.requestAnimationFrame(() => view.invalidateSize());

        return () => {
            window.cancelAnimationFrame(frame);
            view.remove();
        };
        // The pin list is compared as text so the map is rebuilt only when a pin moves.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tileUrl, signature]);

    return <div ref={host} data-testid={testId} className="z-0 overflow-hidden rounded-tile bg-card-2 ring-1 ring-line" style={{ height }} />;
}
