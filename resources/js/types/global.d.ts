import type { Auth } from '@/types/auth';
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
            [key: string]: unknown;
        };
    }
}
