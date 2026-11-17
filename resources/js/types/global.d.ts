import type { Auth } from '@/types/auth';
import type { BillingStatus, PlanLimitBanner } from '@/types/billing';
import type { CompanySummary, WorkspaceSummary } from '@/types/company';
import type { Can } from '@/types/permissions';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /** `auth.user` is null on guest pages; read it only under the app layout. */
            auth: Auth;
            workspace: WorkspaceSummary | null;
            companies: CompanySummary[];
            currentCompany: CompanySummary | null;
            can: Can;
            /** The bell's badge (D78); null outside a company. */
            notifications: { unread: number } | null;
            billingStatus: BillingStatus | null;
            planLimit: PlanLimitBanner | null;
            /** Set while a platform admin impersonates this user (D86): the banner. */
            impersonation: {
                admin: string;
                user: string;
                reason: string;
            } | null;
            [key: string]: unknown;
        };
    }
}
