import { ArrowDownRight, ArrowRight } from 'lucide-react';
import { useId, useState } from 'react';
import type { CSSProperties } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { Modal, ModalPanel } from '@/components/ui/modal';
import { toast } from '@/components/ui/toast';
import { formatRupeeInput, parseRupeesToPaise } from '@/lib/format';

/** The preview's `.stage`: a canvas-coloured box the scrim and modal sit in. */
const STAGE: CSSProperties = {
    position: 'relative',
    minHeight: 470,
    borderRadius: 20,
    overflow: 'hidden',
    background: 'var(--canvas)',
};

function AmountField({
    value,
    onChange,
    error,
}: {
    value: string;
    onChange?: (value: string) => void;
    error?: string;
}) {
    const id = useId();
    const errorId = useId();

    return (
        <div className="cf-field">
            <label className="cf-label" htmlFor={id}>
                Amount
            </label>
            <span className={error ? 'cf-inwrap is-error' : 'cf-inwrap'}>
                <span className="cf-prefix" aria-hidden="true">
                    ₹
                </span>
                <input
                    id={id}
                    type="text"
                    inputMode="decimal"
                    value={value}
                    readOnly={!onChange}
                    onChange={(event) =>
                        onChange?.(formatRupeeInput(event.target.value))
                    }
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                    style={{ fontFamily: 'var(--font-mono)' }}
                />
            </span>
            {error && (
                <span id={errorId} className="cf-errtext">
                    {error}
                </span>
            )}
        </div>
    );
}

function LiveModal() {
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState('');
    const [error, setError] = useState<string>();
    const [saving, setSaving] = useState(false);

    const change = (next: boolean) => {
        setOpen(next);

        if (!next) {
            setAmount('');
            setError(undefined);
            setSaving(false);
        }
    };

    return (
        <Modal
            open={open}
            onOpenChange={change}
            dirty={amount !== ''}
            trigger={
                <Button variant="primary" disc={ArrowRight}>
                    Record contribution
                </Button>
            }
            icon={ArrowDownRight}
            title="Record contribution"
            subtitle="Saarthi Portal JV · capital account"
            onSubmit={(event) => {
                event.preventDefault();
                const paise = parseRupeesToPaise(amount);

                if (paise === null || paise <= 0) {
                    setError('Enter an amount above ₹0.');

                    return;
                }

                setSaving(true);
                window.setTimeout(() => {
                    change(false);
                    toast.success('Contribution recorded');
                }, 900);
            }}
            footer={
                <>
                    <Button size="lg" onClick={() => change(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={ArrowRight}
                        type="submit"
                        loading={saving}
                    >
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </>
            }
        >
            <AmountField
                value={amount}
                onChange={(value) => {
                    setAmount(value);
                    setError(undefined);
                }}
                error={error}
            />
        </Modal>
    );
}

export default {
    name: 'Modal',
    group: 'Feedback and overlays',
    order: 3,
    subtitle:
        'Centred dialog over the scrim · header, body, footer, error and saving states',
    render: () => (
        <>
            <div style={STAGE}>
                <div className="cf-scrim" aria-hidden="true" />
                <ModalPanel
                    icon={ArrowDownRight}
                    title="Record contribution"
                    subtitle="Saarthi Portal JV · capital account"
                    style={{ top: 24, width: 500 }}
                    footer={
                        <>
                            <Button size="lg">Cancel</Button>
                            <Button
                                variant="primary"
                                size="lg"
                                disc={ArrowRight}
                                loading
                            >
                                Saving…
                            </Button>
                        </>
                    }
                >
                    <AmountField value="0" error="Enter an amount above ₹0." />
                </ModalPanel>
            </div>
            <PvRow>
                <LiveModal />
                <PvNote>
                    Live: focus starts in Amount; the scrim won’t close it once
                    you type
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;
