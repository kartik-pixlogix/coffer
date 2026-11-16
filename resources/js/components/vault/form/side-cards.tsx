import { Check, Minus, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Avatar, initialsFrom } from '@/components/ui/avatar';
import { Icon } from '@/components/ui/icon';
import { ENVIRONMENTS } from '@/components/vault/form/form-state';
import { isStrong, strengthLook } from '@/components/vault/form/strength';
import { vaultRequest } from '@/lib/vault-api';
import { reuse } from '@/routes/vault';
import type { VaultEnvironment, VaultReuseResponse } from '@/types/vault';

const ENV_PILL: Record<VaultEnvironment, string> = {
    production: 'cf-pill--neg',
    staging: 'cf-pill--warn',
    development: 'cf-pill--info',
};

/** Preview card (VaultAddLogin.dc.html): how the item will look in the list. */
export function PreviewCard({
    monogram,
    name,
    vault,
    category,
    environment,
    tags,
}: {
    /** Provider monogram ("GL"), or null to use the name's initials. */
    monogram: string | null;
    name: string;
    vault: string;
    category: string;
    environment: VaultEnvironment | '';
    tags: string[];
}) {
    const title = name.trim() || 'Untitled item';
    const env = ENVIRONMENTS.find((e) => e.value === environment);

    return (
        <section className="cf-card vf-side-card" aria-labelledby="vf-pv-h">
            <h2 id="vf-pv-h" className="cf-dot-title t-title">
                Preview
            </h2>
            <div className="cf-row vf-preview-row">
                <Avatar
                    size="lg"
                    tile
                    className="vf-tile-mono"
                    initials={monogram ?? (initialsFrom(title) || '?')}
                    label={false}
                />
                <span className="cf-stack vf-min0">
                    <span className="t-label-strong vf-ellipsis">{title}</span>
                    <span className="t-caption t-muted">
                        {vault} · {category}
                    </span>
                </span>
            </div>
            {(env || tags.length > 0) && (
                <div className="cf-row vf-pills">
                    {env && (
                        <span className={`cf-pill ${ENV_PILL[env.value]}`}>
                            {env.label}
                        </span>
                    )}
                    {tags.map((t) => (
                        <span key={t} className="cf-pill cf-pill--outline">
                            {t}
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}

type CheckTone = 'ok' | 'bad' | 'todo';

const TONE: Record<CheckTone, { icon: LucideIcon; className: string }> = {
    ok: { icon: Check, className: 'vf-chk-ic--ok' },
    bad: { icon: TriangleAlert, className: 'vf-chk-ic--bad' },
    todo: { icon: Minus, className: 'vf-chk-ic--todo' },
};

function CheckRow({
    tone,
    label,
    sub,
}: {
    tone: CheckTone;
    label: string;
    sub: string;
}) {
    const look = TONE[tone];

    return (
        <li className="chk">
            <span className={`chk-ic ${look.className}`}>
                <Icon icon={look.icon} size={14} strokeWidth={2.5} />
            </span>
            <span className="cf-stack">
                <span className="t-body">{label}</span>
                <span className="t-caption t-muted">{sub}</span>
            </span>
        </li>
    );
}

type ReuseState =
    | { status: 'idle' }
    | { status: 'checking'; for: string }
    | { status: 'done'; for: string; result: VaultReuseResponse }
    | { status: 'hidden'; for: string };

/**
 * Asks the server whether the typed password matches another item (HMAC fingerprints,
 * spec §14.3), 600 ms after typing stops. Locked or failed checks hide the row.
 */
function useReuseCheck(password: string, itemId: number | null): ReuseState {
    const [state, setState] = useState<ReuseState>({ status: 'idle' });

    useEffect(() => {
        if (password === '') {
            return;
        }

        let live = true;
        const timer = window.setTimeout(() => {
            setState({ status: 'checking', for: password });
            vaultRequest<VaultReuseResponse>(reuse().url, {
                password,
                item_id: itemId,
            })
                .then((res) => {
                    if (!live) {
                        return;
                    }

                    setState(
                        res.ok
                            ? {
                                  status: 'done',
                                  for: password,
                                  result: res.data,
                              }
                            : { status: 'hidden', for: password },
                    );
                })
                .catch(() => {
                    if (live) {
                        setState({ status: 'hidden', for: password });
                    }
                });
        }, 600);

        return () => {
            live = false;
            window.clearTimeout(timer);
        };
    }, [password, itemId]);

    if (password === '') {
        return { status: 'idle' };
    }

    // Typing again: show "checking" until the next answer for this value.
    return state.status !== 'idle' && state.for === password
        ? state
        : { status: 'checking', for: password };
}

/** Security check card (VaultAddLogin.dc.html). */
export function SecurityCheckCard({
    scored,
    password,
    score,
    bits,
    savedScore,
    showTotp,
    hasTotp,
    itemId,
}: {
    /** The type has a scored main password. */
    scored: boolean;
    /** The typed main password ('' when none was typed). */
    password: string;
    /** zxcvbn score of the typed password. */
    score: number | null;
    /** zxcvbn guesses of the typed password, in bits. */
    bits: number | null;
    /** Edit mode: the stored password's score, when it wasn't replaced. */
    savedScore: number | null;
    /** Login items: 2FA row. */
    showTotp: boolean;
    hasTotp: boolean;
    /** Edit mode: the item being edited. */
    itemId: number | null;
}) {
    const reuseState = useReuseCheck(scored ? password : '', itemId);
    const effective = password !== '' ? score : savedScore;
    const look = strengthLook(effective);
    const matches =
        reuseState.status === 'done' ? reuseState.result.matches : [];

    return (
        <section
            className="cf-card vf-side-card vf-checks"
            aria-labelledby="vf-sc-h"
        >
            <h2 id="vf-sc-h" className="cf-dot-title t-title vf-checks-title">
                Security check
            </h2>
            <ul className="vf-check-list" aria-live="polite">
                {scored &&
                    (effective === null ? (
                        <CheckRow
                            tone="todo"
                            label="No password yet"
                            sub="Type or generate one"
                        />
                    ) : isStrong(effective) ? (
                        <CheckRow
                            tone="ok"
                            label="Strong password"
                            sub={
                                password === ''
                                    ? `${look.label} · saved`
                                    : bits === null
                                      ? look.label
                                      : `${look.label} · ≈ ${Math.round(bits)} bits`
                            }
                        />
                    ) : (
                        <CheckRow
                            tone="bad"
                            label="Weak password"
                            sub="Use the generator before saving"
                        />
                    ))}
                {showTotp &&
                    (hasTotp ? (
                        <CheckRow
                            tone="ok"
                            label="2FA key added"
                            sub="Codes show next to the password"
                        />
                    ) : (
                        <CheckRow
                            tone="bad"
                            label="No 2FA key"
                            sub="Items without 2FA lower your health score"
                        />
                    ))}
                {reuseState.status === 'checking' && (
                    <CheckRow
                        tone="todo"
                        label="Checking for reuse"
                        sub="Against the items in your vaults"
                    />
                )}
                {reuseState.status === 'done' &&
                    (matches.length > 0 ? (
                        <CheckRow
                            tone="bad"
                            label="Used elsewhere"
                            sub={`Same as ${matches.map((m) => m.name).join(', ')}`}
                        />
                    ) : (
                        <CheckRow
                            tone="ok"
                            label="Not used elsewhere"
                            sub={`Checked against ${reuseState.result.checked} ${reuseState.result.checked === 1 ? 'item' : 'items'} in your vaults`}
                        />
                    ))}
                {itemId === null ? (
                    <CheckRow
                        tone="todo"
                        label="Only you for now"
                        sub="Share it in the next step"
                    />
                ) : (
                    <CheckRow
                        tone="todo"
                        label="Sharing stays as it is"
                        sub="Change it with Save and share"
                    />
                )}
            </ul>
        </section>
    );
}
