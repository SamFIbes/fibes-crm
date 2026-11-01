export type CommandGroup = 'navigation' | 'preferences' | 'account';

export type CommandItem = {
    id: string;
    label: string;
    group: CommandGroup;
    keywords?: string;
    perform: () => void;
};

export function filterCommands(items: CommandItem[], query: string): CommandItem[] {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return items;
    }

    return items.filter((item) => `${item.label} ${item.keywords ?? ''}`.toLowerCase().includes(needle));
}
