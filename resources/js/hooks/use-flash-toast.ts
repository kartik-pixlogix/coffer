import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useToast } from '@/components/ui/toast';
import type { FlashToast } from '@/types/ui';

/**
 * Shows the server's `Inertia::flash('toast', [...])` as a toast. The design has
 * success, error and info toasts only, so a flashed `warning` shows as info.
 */
export function useFlashToast(): void {
    const toast = useToast();

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type === 'warning' ? 'info' : data.type](data.message);
        });
    }, [toast]);
}
