export function discountNeedsApproval(
    percent: number,
    amount: number,
    limitPercent: number | null,
    limitAmount: number | null,
): boolean {
    if (limitPercent === null || limitAmount === null) {
        return false;
    }

    return percent > limitPercent || amount > limitAmount;
}

export function quoteNeedsApproval(input: {
    percent: number;
    amount: number;
    limitPercent: number | null;
    limitAmount: number | null;
    custom: boolean;
}): boolean {
    if (input.custom) {
        return true;
    }

    return discountNeedsApproval(input.percent, input.amount, input.limitPercent, input.limitAmount);
}
