import { Smartphone } from 'lucide-react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { CodeInput } from '@/components/ui/code-input';
import { Icon } from '@/components/ui/icon';

type Props = {
    /** "reveal", "copy", "show the code"… for the heading. */
    action?: string;
    /** Sends the code; resolves with an error message, or null when it worked. */
    onVerify: (code: string) => Promise<string | null>;
    onCancel: () => void;
};

/**
 * "Confirm it’s you to reveal" (Vault.dc.html): the 6-digit code from the authenticator
 * app, Verify and Cancel, inline under the secret that asked for it.
 */
export function StepUpForm({ action = 'reveal', onVerify, onCancel }: Props) {
    const headingId = useId();
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [checking, setChecking] = useState(false);
    const complete = /^\d{6}$/.test(code);

    const submit = async (value = code) => {
        if (!/^\d{6}$/.test(value) || checking) {
            return;
        }

        setChecking(true);
        setError(undefined);
        const failure = await onVerify(value);
        setChecking(false);

        if (failure !== null) {
            setError(failure);
            setCode('');
        }
    };

    return (
        <div className="vh-stepup" role="group" aria-labelledby={headingId}>
            <span className="t-body vh-stepup-title" id={headingId}>
                <Icon icon={Smartphone} size={16} />
                Confirm it’s you to {action}
            </span>
            <span className="t-caption t-muted">
                Enter the 6-digit code from your authenticator app.
            </span>
            <CodeInput
                value={code}
                onChange={(value) => {
                    setCode(value);

                    if (value !== '') {
                        setError(undefined);
                    }
                }}
                onComplete={(value) => void submit(value)}
                verifying={checking}
                verifyingLabel="Checking code"
                error={error}
                autoFocus
            />
            <div className="cf-actions">
                <Button
                    variant="primary"
                    size="sm"
                    disabled={!complete}
                    loading={checking}
                    onClick={() => void submit()}
                >
                    Verify
                </Button>
                <Button size="sm" onClick={onCancel} disabled={checking}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
