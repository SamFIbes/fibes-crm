export function needsSignOff(type: string, priceChanged: boolean): boolean {
    if (type === 'termination') {
        return true;
    }

    return (type === 'upgrade' || type === 'downgrade') && priceChanged;
}

export function whatsAppAllowed(consent: boolean, phone: string | null): boolean {
    return consent && Boolean(phone);
}
