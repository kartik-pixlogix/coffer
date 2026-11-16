import { describe, expect, it } from 'vitest';
import type { ToastApi } from '@/components/ui/toast';
import { showFlashToast } from './use-flash-toast';

function recorder(): { api: ToastApi; shown: string[] } {
    const shown: string[] = [];
    const say = (kind: string) => (message: string) => {
        shown.push(`${kind}:${message}`);

        return shown.length;
    };

    return {
        shown,
        api: {
            success: say('success'),
            error: say('error'),
            info: say('info'),
            dismiss: () => undefined,
        },
    };
}

const flashEvent = (toast?: { type: string; message: string }) =>
    new CustomEvent('inertia:flash', { detail: { flash: { toast } } });

describe('showFlashToast', () => {
    it('shows one toast per flash event, however many listeners see it', () => {
        const { api, shown } = recorder();
        const event = flashEvent({ type: 'success', message: 'Saved' });

        // Two listeners (e.g. a second app instance) handling the same event.
        expect(showFlashToast(event, api)).toBe(true);
        expect(showFlashToast(event, api)).toBe(false);
        expect(shown).toEqual(['success:Saved']);
    });

    it('shows the next event again and maps warning to info', () => {
        const { api, shown } = recorder();

        showFlashToast(flashEvent({ type: 'success', message: 'A' }), api);
        showFlashToast(flashEvent({ type: 'warning', message: 'B' }), api);

        expect(shown).toEqual(['success:A', 'info:B']);
    });

    it('ignores flash data without a toast', () => {
        const { api, shown } = recorder();

        expect(showFlashToast(flashEvent(), api)).toBe(false);
        expect(shown).toEqual([]);
    });
});
