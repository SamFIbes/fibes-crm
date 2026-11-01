function utcDate(year: number, monthIndex: number, day: number): string {
    return new Date(Date.UTC(year, monthIndex, day)).toISOString().slice(0, 10);
}

function parts(anchor: string): [number, number, number] {
    const [year, month, day] = anchor.split('-').map(Number);

    return [year, month - 1, day];
}

export function weekDates(anchor: string): string[] {
    const [year, month, day] = parts(anchor);
    const date = new Date(Date.UTC(year, month, day));
    const sunday = day - date.getUTCDay();

    return Array.from({ length: 7 }, (_, index) => utcDate(year, month, sunday + index));
}

export function monthGrid(anchor: string): string[] {
    const [year, month] = parts(anchor);
    const first = new Date(Date.UTC(year, month, 1));
    const start = 1 - first.getUTCDay();

    return Array.from({ length: 42 }, (_, index) => utcDate(year, month, start + index));
}
