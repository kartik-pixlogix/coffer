import { Trash2 } from 'lucide-react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { toast, ToastView } from '@/components/ui/toast';

const noop = () => undefined;

/** The preview turns the slide-in off and lines toasts up on the left. */
const STATIC = 'animate-none self-start';

export default {
    name: 'Toast',
    group: 'Feedback and overlays',
    order: 6,
    subtitle: 'Success, error and info · top-right, auto-dismiss',
    render: () => (
        <>
            <ToastView className={STATIC}>
                MacBook Air sent for approval
            </ToastView>
            <ToastView
                kind="error"
                className={STATIC}
                action={{ label: 'Retry', onClick: noop }}
            >
                Couldn’t save. Check your connection.
            </ToastView>
            <ToastView kind="info" className={STATIC}>
                Password copied · clipboard clears in 30 s
            </ToastView>
            <ToastView
                className={STATIC}
                icon={Trash2}
                action={{ label: 'Undo', onClick: noop }}
            >
                Expense deleted
            </ToastView>
            <PvRow>
                <Button
                    size="sm"
                    onClick={() =>
                        toast.success('MacBook Air sent for approval')
                    }
                >
                    Success
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        toast.error('Couldn’t save. Check your connection.', {
                            action: {
                                label: 'Retry',
                                onClick: () => toast.success('Saved'),
                            },
                        })
                    }
                >
                    Error
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        toast.info('Password copied · clipboard clears in 30 s')
                    }
                >
                    Info
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        toast.success('Expense deleted', {
                            icon: Trash2,
                            action: {
                                label: 'Undo',
                                onClick: () =>
                                    toast.success('Expense restored'),
                            },
                        })
                    }
                >
                    With Undo
                </Button>
                <PvNote>
                    Live: top right, up to three; 2.6 s (8 s with an action);
                    errors stay
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;
