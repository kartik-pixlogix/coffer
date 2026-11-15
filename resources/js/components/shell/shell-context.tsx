import { createContext, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import {
    placeholderCompanies,
    placeholderWorkspace,
} from '@/lib/placeholder-shell';
import type { CompanySummary, WorkspaceSummary } from '@/types';

type ShellContextValue = {
    workspace: WorkspaceSummary;
    companies: CompanySummary[];
    currentCompany: CompanySummary;
    switchCompany: (id: number) => void;
};

const ShellContext = createContext<ShellContextValue | null>(null);

/**
 * Workspace and company for the shell (switcher + breadcrumb).
 * Phase 0: placeholder data switched client-side only. Phase 2 sources it from
 * shared props and switching becomes a server request (spec §2).
 */
export function ShellProvider({ children }: { children: ReactNode }) {
    const [currentId, setCurrentId] = useState(placeholderCompanies[0].id);

    const currentCompany =
        placeholderCompanies.find((c) => c.id === currentId) ??
        placeholderCompanies[0];

    return (
        <ShellContext.Provider
            value={{
                workspace: placeholderWorkspace,
                companies: placeholderCompanies,
                currentCompany,
                switchCompany: setCurrentId,
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
