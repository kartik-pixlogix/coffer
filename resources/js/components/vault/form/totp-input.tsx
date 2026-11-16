import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Field, useFieldIds } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import {
    TotpRing,
    splitTotpCode,
    useTotpCountdown,
} from '@/components/ui/totp-ring';
import {
    SavedSecretAction,
    savedPlaceholder,
} from '@/components/vault/form/secret-input';
import type { SavedSecretProps } from '@/components/vault/form/secret-input';
import { TOTP_PERIOD, totpCode, totpKeyState } from '@/lib/totp';
import { cn } from '@/lib/utils';

type TotpInputProps = SavedSecretProps & {
    label: string;
    value: string;
    onValueChange: (value: string) => void;
    /** "GitLab" for "Paste the key from GitLab’s 2FA screen." */
    providerName: string | null;
    error?: ReactNode;
    id?: string;
    fieldClassName?: string;
};

/** The current code for a valid key, refreshed with each 30-second window. */
function useLiveCode(key: string, enabled: boolean, left: number) {
    const [code, setCode] = useState<{ for: string; code: string } | null>(
        null,
    );
    // A new window starts when the countdown jumps back up to the full period.
    const windowStart = left === TOTP_PERIOD;

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let live = true;

        totpCode(key)
            .then((next) => {
                if (live && next !== null) {
                    setCode({ for: key, code: next });
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [key, enabled, windowStart]);

    return enabled && code?.for === key ? code.code : null;
}

/**
 * The 2FA setup key (VaultAddLogin.dc.html): accepts spaces, lower case and otpauth://
 * links, and shows the live code with its countdown ring so it can be checked against
 * the authenticator app before saving. The key is never stored by the browser.
 */
export function TotpInput({
    label,
    value,
    onValueChange,
    providerName,
    error,
    id: idProp,
    fieldClassName,
    saved,
    cleared,
    onClear,
    onUndoClear,
}: TotpInputProps) {
    const [touched, setTouched] = useState(false);
    const state = totpKeyState(value);
    const left = useTotpCountdown(TOTP_PERIOD);
    const code = useLiveCode(value, state === 'ok', left);
    const where = providerName ? `${providerName}’s` : 'the site’s';
    const ownError =
        state === 'invalid'
            ? 'Setup keys use letters A–Z and digits 2–7.'
            : state === 'short' && touched
              ? 'That key looks too short. Paste the whole key.'
              : undefined;
    const shownError = error ?? ownError;
    const help =
        state === 'ok'
            ? `Paste the key from ${where} 2FA screen. The code on the right should match your app.`
            : `Optional. Paste the key from ${where} 2FA screen.`;
    const ids = useFieldIds(idProp, { help, error: shownError });

    return (
        <Field
            ids={ids}
            label={label}
            labelAction={
                <SavedSecretAction
                    saved={saved}
                    cleared={cleared}
                    typed={value !== ''}
                    label="2FA key"
                    onClear={onClear}
                    onUndoClear={onUndoClear}
                />
            }
            help={help}
            error={shownError}
            className={fieldClassName}
        >
            <span className={cn('cf-inwrap', ids.invalid && 'is-error')}>
                <Icon icon={Clock} size={16} />
                <input
                    id={ids.id}
                    type="text"
                    className="vf-mono vf-totp-key"
                    value={value}
                    placeholder={savedPlaceholder(
                        { saved, cleared },
                        'e.g. JBSW Y3DP EHPK 3PXP',
                    )}
                    autoComplete="off"
                    spellCheck={false}
                    autoCapitalize="characters"
                    aria-invalid={ids.invalid || undefined}
                    aria-describedby={ids.describedBy}
                    onChange={(event) => {
                        setTouched(false);
                        onValueChange(event.currentTarget.value);
                    }}
                    onBlur={() => setTouched(true)}
                />
                {code !== null && (
                    <span className="cf-row vf-totp-live">
                        <span className="t-money-sm">
                            <span className="cf-sr">Current code </span>
                            {splitTotpCode(code)}
                        </span>
                        <TotpRing secondsLeft={left} size={28} tone="light" />
                    </span>
                )}
            </span>
        </Field>
    );
}
