import { Head, router } from '@inertiajs/react';
import { Ban, LogOut } from 'lucide-react';
import { signOut } from '@/components/shell/sign-out';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { switchMethod } from '@/routes/companies';

type Props = {
    workspace: string;
    /** The person's companies in other, active workspaces. */
    otherCompanies: { id: number; name: string; workspace: string }[];
};

/**
 * A suspended workspace (spec §22, D86): every page of its companies lands here (423).
 * Explains it, offers the person's companies elsewhere, and signing out.
 */
export default function Suspended({ workspace, otherCompanies }: Props) {
    return (
        <div className="cf-page">
            <Head title="Workspace suspended" />
            <main
                className="flex min-h-screen items-center justify-center p-4"
                aria-labelledby="suspended-h"
            >
                <div
                    className="cf-card flex flex-col items-start gap-4"
                    style={{ maxWidth: 560, padding: 32 }}
                >
                    <span className="denied-ic" aria-hidden="true">
                        <Icon icon={Ban} size={24} />
                    </span>
                    <h1 id="suspended-h" className="t-display denied-title">
                        {workspace} is suspended
                    </h1>
                    <p className="t-body t-muted denied-body">
                        Nobody can open this workspace’s companies right now.
                        Nothing has been deleted: the books, team and vault are
                        kept as they were. The workspace owner can contact
                        Coffer support to restore access.
                    </p>
                    {otherCompanies.length > 0 && (
                        <div className="flex w-full flex-col gap-2">
                            <span className="t-label-strong">
                                Open another company
                            </span>
                            {otherCompanies.map((company) => (
                                <Button
                                    key={company.id}
                                    variant="secondary"
                                    className="justify-between"
                                    onClick={() =>
                                        router.post(
                                            switchMethod.url(company.id),
                                        )
                                    }
                                >
                                    {company.name}
                                    <span className="t-caption t-muted">
                                        {company.workspace}
                                    </span>
                                </Button>
                            ))}
                        </div>
                    )}
                    <Button variant="ghost" icon={LogOut} onClick={signOut}>
                        Sign out
                    </Button>
                </div>
            </main>
        </div>
    );
}
