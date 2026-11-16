import { useEffect, useState } from 'react';

/*
 * Password strength for the vault form (spec §14.4: "shows strength using zxcvbn").
 * zxcvbn and its common dictionaries are big, so they load the first time a password
 * field is used. The score (0–4) is what the server stores as `strength`.
 */

export type StrengthResult = { score: number; bits: number };

type Checker = (password: string) => StrengthResult;

let loading: Promise<Checker> | null = null;

/** zxcvbn gets slow on very long input; the first 100 characters decide the score. */
const MAX_SCORED = 100;

/** Loads zxcvbn once (dictionary + keyboard graphs from language-common). */
export function loadStrength(): Promise<Checker> {
    loading ??= Promise.all([
        import('@zxcvbn-ts/core'),
        import('@zxcvbn-ts/language-common'),
    ])
        .then(([core, common]) => {
            const factory = new core.ZxcvbnFactory({
                dictionary: { ...common.dictionary },
                graphs: common.adjacencyGraphs,
            });

            return (password: string) => {
                const result = factory.check(password.slice(0, MAX_SCORED));

                return {
                    score: result.score,
                    bits: result.guessesLog10 * Math.log2(10),
                };
            };
        })
        .catch((error: unknown) => {
            loading = null;
            throw error;
        });

    return loading;
}

/**
 * zxcvbn score (and guesses as bits) of `password`, or null while it is empty or
 * zxcvbn is still loading. Pass `active` false to not load anything yet.
 */
export function useStrengthResult(
    password: string,
    active = true,
): StrengthResult | null {
    const [result, setResult] = useState<{ for: string } & StrengthResult>({
        for: '',
        score: 0,
        bits: 0,
    });

    useEffect(() => {
        if (!active || password === '') {
            return;
        }

        let live = true;

        loadStrength()
            .then((check) => {
                if (live) {
                    setResult({ for: password, ...check(password) });
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [password, active]);

    if (password === '') {
        return null;
    }

    return result.for === password
        ? { score: result.score, bits: result.bits }
        : null;
}

/** Just the 0–4 score (see useStrengthResult). */
export function useStrength(password: string, active = true): number | null {
    return useStrengthResult(password, active)?.score ?? null;
}

export type StrengthLook = {
    label: string;
    /** Text colour class: t-neg / t-warn / t-pos / t-muted. */
    ink: string;
    /** The 4 cf-meter-dots backgrounds. */
    dots: [string, string, string, string];
    /** Help line under the field. */
    help: string;
};

const LABELS = ['Weak', 'Weak', 'Fair', 'Strong', 'Very strong'];

/** zxcvbn score → the board's label, colour and dots (Weak 1 dot, Fair 2, Strong 3, Very strong 4). */
export function strengthLook(score: number | null): StrengthLook {
    if (score === null) {
        return {
            label: 'Empty',
            ink: 't-muted',
            dots: [
                'var(--line-strong)',
                'var(--line-strong)',
                'var(--line-strong)',
                'var(--line-strong)',
            ],
            help: 'Type one or generate it.',
        };
    }

    const s = Math.max(0, Math.min(4, Math.round(score)));
    const filled = Math.max(1, s);
    const colour =
        s <= 1
            ? 'var(--negative)'
            : s === 2
              ? 'var(--warning)'
              : 'var(--positive)';
    const dot = (k: number) => (k < filled ? colour : 'var(--line-strong)');

    return {
        label: LABELS[s],
        ink: s <= 1 ? 't-neg' : s === 2 ? 't-warn' : 't-pos',
        dots: [dot(0), dot(1), dot(2), dot(3)],
        help: isStrong(s)
            ? 'Good. Coffer keeps a history of old passwords.'
            : 'Easy to guess. Generate a longer one instead.',
    };
}

/** Strong or Very strong (the board's "Strong password" check); Fair still counts as weak. */
export function isStrong(score: number | null): boolean {
    return score !== null && score >= 3;
}
