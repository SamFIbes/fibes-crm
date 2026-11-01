export type DurationUnit = 'minutes' | 'hours' | 'days';

export function displayMinutes(minutes: number, kind: 'respond' | 'resolve'): { value: number; unit: DurationUnit } {
    if (kind === 'resolve' && minutes > 0 && minutes % 1440 === 0) {
        return { value: minutes / 1440, unit: 'days' };
    }

    if (minutes >= 60 && minutes % 60 === 0) {
        return { value: minutes / 60, unit: 'hours' };
    }

    return { value: minutes, unit: 'minutes' };
}

export function toMinutes(value: number, unit: DurationUnit): number {
    if (unit === 'days') {
        return Math.round(value * 1440);
    }

    if (unit === 'hours') {
        return Math.round(value * 60);
    }

    return Math.round(value);
}
