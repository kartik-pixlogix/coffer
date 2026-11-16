import { router, usePage } from '@inertiajs/react';
import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { switchMethod } from '@/routes/companies';
import type { Can, CompanySummary, WorkspaceSummary } from '@/types';

type ShellContextValue = {
    workspace: WorkspaceSummary;
    companies: CompanySummary[];
    currentCompany: CompanySummary;
    can: Can;
    switchCompany: (id: number) => void;
};

const ShellContext = createContext<ShellContextValue | null>(null);

/**
 * Workspace, companies and permissions for the shell (switcher, breadcrumb, rail),
 * from the shared Inertia props. Switching asks the server, which keeps the
 * choice in the session and lands on the role's first page (spec §2).
 */
export function ShellProvider({ children }: { children: ReactNode }) {
    const { workspace, companies, currentCompany, can } = usePage().props;

    if (workspace === null || currentCompany === null) {
        throw new Error('The app shell needs a current company.');
    }

    return (
        <ShellContext.Provider
            value={{
                workspace,
                companies,
                currentCompany,
                can,
                switchCompany: (id) => {
                    if (id !== currentCompany.id) {
                        router.post(switchMethod(id).url);
                    }
                },
            }}
        >
            {children}
        </ShellContext.Provider>
    );
}

export function useShell(): ShellContextValue {
    const value = useContext(ShellContext);

    if (value === null) {
        throw new Error('useShell must be used inside <ShellProvider>.');
    }

    return value;
}
