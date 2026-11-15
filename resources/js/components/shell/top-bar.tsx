import { Link } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AccountMenu } from '@/components/shell/account-menu';
import { CommandMenu } from '@/components/shell/command-menu';
import { NotificationsButton } from '@/components/shell/notifications-button';
import { useShell } from '@/components/shell/shell-context';
import { CompanySwitcher } from '@/components/ui/company-switcher';
import { IconButton } from '@/components/ui/icon-button';
import { Tooltip } from '@/components/ui/tooltip';
import { useTheme } from '@/hooks/use-theme';
import { formatLongDate } from '@/lib/format';
import { dashboard } from '@/routes';

function ThemeToggle() {
    const { theme, toggleTheme } = useTheme();
    const label =
        theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';

    return (
        <Tooltip label={label}>
            <IconButton
                icon={theme === 'dark' ? Sun : Moon}
                size="lg"
                aria-label={label}
                onClick={toggleTheme}
            />
        </Tooltip>
    );
}

/** Today's date, rendered on the client so it follows the viewer's clock. */
function Today() {
    const [today, setToday] = useState<string | null>(null);

    useEffect(() => setToday(formatLongDate(new Date())), []);

    return (
        <span className="t-caption t-muted mr-1" suppressHydrationWarning>
            {today}
        </span>
    );
}

function ShellCompanySwitcher() {
    const { workspace, companies, currentCompany, switchCompany } = useShell();

    return (
        <CompanySwitcher
            companies={companies}
            currentId={currentCompany.id}
            workspaceName={workspace.name}
            onSwitch={switchCompany}
            newCompanyDisabledReason="available soon"
        />
    );
}

/**
 * Top bar (design/screens/Main.dc.html): wordmark, company switcher and the ⌘K
 * search on the left; date, theme toggle, notifications and the account menu on the right.
 */
export function TopBar() {
    return (
        <header className="cf-topbar">
            <div className="cf-topbar-l">
                <Link href={dashboard()} className="cf-wordmark">
                    Coffer
                </Link>
                <ShellCompanySwitcher />
                <CommandMenu />
            </div>
            <div className="cf-topbar-r">
                <Today />
                <ThemeToggle />
                <NotificationsButton />
                <AccountMenu />
            </div>
        </header>
    );
}
