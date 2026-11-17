import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { UpgradeModal } from '@/components/billing/upgrade-modal';
import { jsonRequest } from '@/lib/json-request';
import { planLimitMessage, UPGRADE_EVENT } from '@/lib/upgrade';
import type { UpgradeRequest } from '@/lib/upgrade';
import { plans as plansRoute } from '@/routes/billing';
import type { UpgradeContext } from '@/types';

/**
 * Mounted once in the app shell (D82): opens the Upgrade modal when any Inertia visit
 * comes back with a `plan_limit` error (a create past the plan, or a change while
 * read-only), or when something calls openUpgrade(). Plans load when it opens.
 */
export function UpgradeHost() {
    const billing = usePage().props.billingStatus;
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [context, setContext] = useState<UpgradeContext | null>(null);

    useEffect(() => {
        const show = (why: string | null) => {
            setMessage(why);
            setOpen(true);
            void jsonRequest<UpgradeContext>(plansRoute().url).then((r) => {
                if (r.ok) {
                    setContext(r.data);
                }
            });
        };

        const onEvent = (event: Event) =>
            show(
                (event as CustomEvent<UpgradeRequest>).detail?.message ?? null,
            );

        window.addEventListener(UPGRADE_EVENT, onEvent);
        const off = router.on('error', (event) => {
            const why = planLimitMessage(event.detail.errors);

            if (why !== null) {
                show(why);
            }
        });

        return () => {
            window.removeEventListener(UPGRADE_EVENT, onEvent);
            off();
        };
    }, []);

    return (
        <UpgradeModal
            open={open}
            onOpenChange={setOpen}
            context={context}
            message={message}
            canManage={billing?.canManage === true}
        />
    );
}
