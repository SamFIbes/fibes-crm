export type Tile = { key: string; value: string; href: string };

export type AttentionItem = {
    id: number;
    title: string | null;
    meta: string | null;
    meta_ar: string | null;
    href: string;
    tone: string;
    number?: string;
    when?: string | null;
};

export type Queue = { key: string; href: string; count: number; items: AttentionItem[] };
