import * as Dialog from '@radix-ui/react-dialog';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { drawerSpring } from '@/lib/motion';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function Drawer({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
    width = 560,
    className,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    width?: number;
    className?: string;
}) {
    const { dir, t } = useI18n();
    const reduce = useReducedMotion();
    const off = dir === 'rtl' ? '-100%' : '100%';

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <AnimatePresence>
                {open ? (
                    <Dialog.Portal forceMount>
                        <Dialog.Overlay asChild>
                            <motion.div className="fixed inset-0 z-50 bg-[#05061a]/55 backdrop-blur-[3px]" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: reduce ? 0 : 0.22 }} />
                        </Dialog.Overlay>
                        <Dialog.Content asChild>
                            <motion.div
                                dir={dir}
                                data-testid="drawer"
                                className={cn('fixed inset-y-2 end-2 z-50 flex max-w-[calc(100vw-16px)] flex-col overflow-hidden rounded-[22px] border border-line-2 bg-card shadow-pop outline-none', className)}
                                style={{ width }}
                                initial={reduce ? false : { x: off, opacity: 0.6 }}
                                animate={{ x: 0, opacity: 1 }}
                                exit={reduce ? { opacity: 0 } : { x: off, opacity: 0.6, transition: { duration: 0.22, ease: [0.4, 0, 1, 1] } }}
                                transition={reduce ? { duration: 0 } : drawerSpring}
                            >
                                <div className="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
                                    <div className="min-w-0">
                                        <Dialog.Title className="text-xl font-semibold tracking-tight">{title}</Dialog.Title>
                                        {description ? <Dialog.Description className="mt-0.5 text-sm text-muted">{description}</Dialog.Description> : <Dialog.Description className="sr-only">{title}</Dialog.Description>}
                                    </div>
                                    <Dialog.Close className="grid size-11 shrink-0 place-items-center rounded-[10px] text-muted transition hover:bg-well hover:text-text" aria-label={t('nav.close_menu')}>
                                        <X className="size-4" />
                                    </Dialog.Close>
                                </div>
                                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                                {footer ? <div className="border-t border-line bg-card-2/60 px-6 py-3">{footer}</div> : null}
                            </motion.div>
                        </Dialog.Content>
                    </Dialog.Portal>
                ) : null}
            </AnimatePresence>
        </Dialog.Root>
    );
}
