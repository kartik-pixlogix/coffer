import { router } from '@inertiajs/react';
import { logout } from '@/routes';

/** Sign out from the rail, the account menu or the command palette. */
export function signOut(): void {
    router.flushAll();
    router.post(logout());
}
