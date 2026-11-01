export type CatalogPlan = {
    id: number;
    product_id: number;
    name: string | null;
    name_ar?: string | null;
    service_type: string | null;
    down_mbps: number | null;
    up_mbps: number | null;
    price: string | null;
    price_lbp: string | null;
    needs_survey?: boolean;
};

export function planLabel(plan: CatalogPlan, arabic = false): string {
    const name = arabic ? plan.name_ar || plan.name : plan.name;
    const speed = plan.down_mbps ? `${plan.down_mbps}/${plan.up_mbps ?? '—'}` : '';
    const price = plan.price ? `$${plan.price}` : '';

    return [name, speed, price].filter(Boolean).join(' · ');
}
