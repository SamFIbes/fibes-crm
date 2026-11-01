export function bulkAllowed(rows: { low_risk: boolean; selected: boolean }[]): boolean {
    const picked = rows.filter((row) => row.selected);

    return picked.length > 0 && picked.every((row) => row.low_risk);
}
