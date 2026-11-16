import { createInertiaApp } from '@inertiajs/react';
import { ToastProvider } from '@/components/ui/toast';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useFlashToast } from '@/hooks/use-flash-toast';
import AppLayout from '@/layouts/app-layout';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Coffer';

/** Turns flashed `toast` data from the server into toasts. */
function FlashToasts() {
    useFlashToast();

    return null;
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'auth/accept-invite':
            case name === 'companies/create':
            case name.startsWith('dev/'):
                return null;
            case name.startsWith('auth/'):
                return AuthSplitLayout;
            // The starter kit's account pages, until Profile replaces them.
            case [
                'settings/profile',
                'settings/security',
                'settings/appearance',
            ].includes(name):
                return [AppLayout, SettingsLayout];
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
