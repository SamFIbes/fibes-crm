import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Toaster as Sonner, toast } from 'sonner';
import { useI18n } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';
import type { SharedProps } from '@/types';

export function Toaster() {
    const { theme } = useTheme();
    const { dir } = useI18n();

    return <Sonner theme={theme} dir={dir} position="top-center" closeButton />;
}

export function FlashListener() {
    const { flash } = usePage<SharedProps>().props;
    const seen = useRef<string | null>(null);
    const status = flash.status ?? null;

    useEffect(() => {
        if (!status) {
            seen.current = null;

            return;
        }

        if (seen.current === status) {
            return;
        }

        seen.current = status;
        toast.success(status);
    }, [status]);

    return null;
}
