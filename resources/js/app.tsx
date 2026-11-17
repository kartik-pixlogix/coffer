import { createInertiaApp } from '@inertiajs/react';
import { ToastProvider } from '@/components/ui/toast';
import { TooltipProvider } from '@/components/ui/tooltip';
import { FlashToasts } from '@/hooks/use-flash-toast';
import AppLayout from '@/layouts/app-layout';
import { installSessionExpiryHandler } from '@/lib/session-expiry';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';
import MarketingLayout from '@/layouts/marketing-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Coffer';

// A request that finds the session gone opens the re-auth dialog over the page (D30).
installSessionExpiryHandler();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'auth/accept-invite':
            case name === 'companies/create':
            case name.startsWith('dev/'):
                return null;
            case name.startsWith('marketing/'):
                return MarketingLayout;
            case name.startsWith('auth/'):
                return AuthSplitLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider>
                <ToastProvider>
                    {app}
                    <FlashToasts />
                </ToastProvider>
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
