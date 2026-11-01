export function mapPoint(input: string): { lat: string; lng: string } | null {
    const patterns = [
        /@(-?\d+\.\d+),(-?\d+\.\d+)/,
        /[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/,
        /(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/,
    ];

    for (const pattern of patterns) {
        const match = input.match(pattern);

        if (match) {
            return { lat: match[1], lng: match[2] };
        }
    }

    return null;
}

export function whatsAppLink(e164: string, text: string): string {
    const digits = e164.replace(/\D/g, '');

    return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
}
