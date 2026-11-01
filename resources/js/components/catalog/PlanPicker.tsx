import { planLabel, type CatalogPlan } from '@/lib/catalog';

export function PlanPicker({
    id,
    plans,
    value,
    arabic,
    onChange,
}: {
    id: string;
    plans: CatalogPlan[];
    value: string;
    arabic: boolean;
    onChange: (plan: CatalogPlan | null) => void;
}) {
    return (
        <select
            id={id}
            className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm"
            value={value}
            onChange={(event) => onChange(plans.find((plan) => String(plan.id) === event.target.value) ?? null)}
        >
            <option value="">—</option>
            {plans.map((plan) => (
                <option key={plan.id} value={plan.id}>{planLabel(plan, arabic)}</option>
            ))}
        </select>
    );
}
