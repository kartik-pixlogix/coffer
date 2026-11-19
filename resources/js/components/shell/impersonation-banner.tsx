import { router, usePage } from '@inertiajs/react';
import { UserRoundCog } from 'lucide-react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { stop } from '@/routes/impersonation';

/**
 * While a platform admin impersonates this user (spec §22, D86, D111): who is viewing as
 * whom and why, on every page, with "Stop impersonating". Vault secrets and account
 * security stay locked meanwhile; a suspended workspace says so.
 */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (impersonation === null || impersonation === undefined) {
        return null;
    }

    return (
        <AlertBanner
            variant={impersonation.suspended ? 'neg' : 'warn'}
            slim
            role="status"
            icon={UserRoundCog}
            title={`${impersonation.admin} (Coffer platform) viewing as ${impersonation.user}`}
            action={
                <Button
                    variant="secondary"
                    onClick={() => router.post(stop.url())}
                >
                    Stop impersonating
                </Button>
            }
        >
            {impersonation.suspended
                ? 'This workspace is suspended: its people can’t use it. '
                : ''}
            Reason: {impersonation.reason}. Vault secrets and account security
            stay locked.
        </AlertBanner>
    );
}
