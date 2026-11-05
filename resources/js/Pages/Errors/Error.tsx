import { Head, Link, usePage } from '@inertiajs/react';
import { Logo } from '@/components/brand/Logo';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { Nebula } from '@/components/shell/Nebula';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

const known = [403, 404, 419, 500, 503] as const;

type KnownStatus = (typeof known)[number];

export default function ErrorPage({ status }: { status: number }) {
    const { t } = useI18n();
    const { auth } = usePage<SharedProps>().props;
    const code = (known.includes(status as KnownStatus) ? status : 500) as KnownStatus;
    const signedIn = Boolean(auth?.user);

    return (
        <div className="relative flex min-h-dvh flex-col">
            <Nebula />
            <header className="relative z-10 flex items-center justify-between gap-3 px-6 py-6">
                <Logo />
                <div className="flex items-center gap-1">
                    <LanguageSwitch />
                    <ThemeToggle />
                </div>
            </header>
            <main id="content" className="relative z-10 flex flex-1 items-center justify-center px-6 pb-16">
                <Head title={String(code)} />
                <div className="auth-card max-w-md px-8 py-10 text-center">
                    <p className="bg-brand-gradient bg-clip-text text-5xl font-semibold tracking-tight text-transparent">{code}</p>
                    <h1 className="mt-4 text-2xl font-semibold tracking-tight">{t(`errors.${code}_title`)}</h1>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">{t(`errors.${code}_body`)}</p>
                    <Button asChild className="mt-8">
                        <Link href={signedIn ? '/dashboard' : '/login'}>{signedIn ? t('errors.home') : t('errors.login')}</Link>
                    </Button>
                </div>
            </main>
        </div>
    );
}
