import { X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** "Meta ads — October" or "3 expenses". */
    what: string;
    busy?: boolean;
    error?: string;
    onReject: (reason: string) => void;
};

/** Reject with a reason (spec §10: a rejection needs one). One expense or a bulk selection. */
export function RejectDialog({
    open,
    onOpenChange,
    what,
    busy = false,
    error,
    onReject,
}: Props) {
    const [reason, setReason] = useState('');
    const [shown, setShown] = useState(false);

    if (open !== shown) {
        setShown(open);
        setReason('');
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={`Reject ${what}?`}
            subtitle="The submitter sees your reason. Nothing is posted."
            icon={X}
            size="sm"
            footer={
                <>
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="danger"
                        size="lg"
                        loading={busy}
                        disabled={reason.trim() === ''}
                        onClick={() => onReject(reason.trim())}
                    >
                        Reject
                    </Button>
                </>
            }
        >
            <Textarea
                id="rj-r"
                label="Reason"
                rows={3}
                limit={500}
                autoFocus
                placeholder="Personal purchase, duplicate, missing receipt…"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                error={error}
            />
        </Modal>
    );
}
