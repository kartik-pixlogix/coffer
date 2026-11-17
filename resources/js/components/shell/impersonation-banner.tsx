import { router, usePage } from '@inertiajs/react';
import { UserRoundCog } from 'lucide-react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { stop } from '@/routes/impersonation';

/**
 * While a platform admin impersonates this user (spec §22, D86): who, as whom and why,
 * on every page, with "Stop impersonating". Vault secrets are locked meanwhile.
 */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (impersonation === null || impersonation === undefined) {
        return null;
    }

    return (
        <AlertBanner
            variant="warn"
            slim
            role="status"
            icon={UserRoundCog}
            title={`${impersonation.admin} is signed in as ${impersonation.user}`}
            action={
                <Button
                    variant="secondary"
                    onClick={() => router.post(stop.url())}
                >
                    Stop impersonating
                </Button>
            }
        >
            Reason: {impersonation.reason}. Vault secrets stay locked.
        </AlertBanner>
    );
}
