import { useEffect, useRef } from 'react';

/*
 * Original 2D canvas dust for the sign-in stage.
 * No WebGL, no runtime CDN, no third-party particle library.
 * prefers-reduced-motion paints one still frame and does not loop.
 */

const FALLBACK_COLORS = ['31,209,122', '25,195,214', '24,207,224'];

function brandColors(): string[] {
    if (typeof document === 'undefined') {
        return FALLBACK_COLORS;
    }

    const styles = getComputedStyle(document.documentElement);
    const parsed = ['--brand-mint', '--brand-aqua', '--brand-sky'].map((name) => rgbChannels(styles.getPropertyValue(name)));

    if (!parsed.every((color) => color !== null)) {
        return FALLBACK_COLORS;
    }

    const [mint, aqua, sky] = parsed as string[];

    return [mint, aqua, lighten(sky)];
}

function lighten(channels: string): string {
    return channels
        .split(',')
        .map((part) => Math.round(Number(part) + (255 - Number(part)) * 0.62))
        .join(',');
}

function rgbChannels(input: string): string | null {
    const value = input.trim();
    const hex = value.match(/^#([0-9a-f]{6})$/i);

    if (hex) {
        const n = Number.parseInt(hex[1], 16);

        return `${(n >> 16) & 255},${(n >> 8) & 255},${n & 255}`;
    }

    const rgb = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);

    if (!rgb) {
        return null;
    }

    return `${Math.round(Number(rgb[1]))},${Math.round(Number(rgb[2]))},${Math.round(Number(rgb[3]))}`;
}

type Dust = {
    x: number;
    y: number;
    r: number;
    vx: number;
    vy: number;
    alpha: number;
    color: string;
};

function motionAllowed(): boolean {
    return typeof window !== 'undefined' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function contextOf(canvas: HTMLCanvasElement): CanvasRenderingContext2D | null {
    try {
        return canvas.getContext('2d');
    } catch {
        return null;
    }
}

function seed(width: number, height: number, colors: string[]): Dust[] {
    const count = Math.round(Math.min(56, Math.max(18, (width * height) / 22000)));
    const dust: Dust[] = [];

    for (let index = 0; index < count; index += 1) {
        dust.push({
            x: Math.random() * width,
            y: Math.random() * height,
            r: 1.1 + Math.random() * 1.8,
            vx: (Math.random() - 0.5) * 0.28,
            vy: (Math.random() - 0.5) * 0.28,
            alpha: 0.28 + Math.random() * 0.45,
            color: colors[index % colors.length],
        });
    }

    return dust;
}

function draw(ctx: CanvasRenderingContext2D, dust: Dust[], width: number, height: number, links: boolean) {
    ctx.clearRect(0, 0, width, height);

    if (links) {
        for (let i = 0; i < dust.length; i += 1) {
            for (let j = i + 1; j < dust.length; j += 1) {
                const a = dust[i];
                const b = dust[j];
                const dx = a.x - b.x;
                const dy = a.y - b.y;
                const dist = Math.hypot(dx, dy);

                if (dist > 118) {
                    continue;
                }

                ctx.strokeStyle = `rgba(255,255,255,${(1 - dist / 118) * 0.22})`;
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(a.x, a.y);
                ctx.lineTo(b.x, b.y);
                ctx.stroke();
            }
        }
    }

    for (const dot of dust) {
        ctx.fillStyle = `rgba(${dot.color},${dot.alpha})`;
        ctx.beginPath();
        ctx.arc(dot.x, dot.y, dot.r, 0, Math.PI * 2);
        ctx.fill();
    }
}

export function ParticleField() {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const motion = motionAllowed();

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }

        const ctx = contextOf(canvas);
        if (!ctx) {
            return;
        }

        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        let frame = 0;
        let running = false;
        let dust: Dust[] = [];
        let width = 0;
        let height = 0;

        const resize = () => {
            const rect = canvas.getBoundingClientRect();
            width = rect.width;
            height = rect.height;

            if (width < 2 || height < 2) {
                return;
            }

            const dpr = Math.min(window.devicePixelRatio || 1, 1.75);
            canvas.width = Math.floor(width * dpr);
            canvas.height = Math.floor(height * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            dust = seed(width, height, brandColors());
            draw(ctx, dust, width, height, false);
        };

        let last = 0;

        const step = (now: number) => {
            if (!running) {
                return;
            }

            const delta = Math.min(34, now - last) / 16.67;
            last = now;

            for (const dot of dust) {
                dot.x += dot.vx * delta;
                dot.y += dot.vy * delta;

                if (dot.x < -8 || dot.x > width + 8) {
                    dot.vx *= -1;
                }

                if (dot.y < -8 || dot.y > height + 8) {
                    dot.vy *= -1;
                }
            }

            draw(ctx, dust, width, height, width >= 720);
            frame = window.requestAnimationFrame(step);
        };

        const start = () => {
            if (running || query.matches || document.hidden || width < 2 || height < 2) {
                return;
            }

            running = true;
            last = performance.now();
            frame = window.requestAnimationFrame(step);
        };

        const stop = () => {
            running = false;
            window.cancelAnimationFrame(frame);
        };

        const onMotion = () => {
            canvas.dataset.motion = query.matches ? 'off' : 'on';
            stop();

            if (!query.matches) {
                start();
            }
        };

        const onHide = () => {
            if (document.hidden) {
                stop();
            } else {
                start();
            }
        };

        resize();
        canvas.dataset.motion = query.matches ? 'off' : 'on';
        start();

        const observer = new ResizeObserver(resize);
        observer.observe(canvas);
        query.addEventListener('change', onMotion);
        document.addEventListener('visibilitychange', onHide);

        return () => {
            stop();
            observer.disconnect();
            query.removeEventListener('change', onMotion);
            document.removeEventListener('visibilitychange', onHide);
        };
    }, []);

    return (
        <canvas
            ref={canvasRef}
            aria-hidden="true"
            data-testid="guest-particles"
            data-motion={motion ? 'on' : 'off'}
            className="guest-particles"
        />
    );
}
