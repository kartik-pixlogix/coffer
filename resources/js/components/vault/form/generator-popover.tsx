import { RefreshCw, WandSparkles } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/ui/icon-button';
import { Popover } from '@/components/ui/popover';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Toggle } from '@/components/ui/toggle';
import { strengthLook, useStrength } from '@/components/vault/form/strength';
import {
    StrengthDots,
    StrengthLabel,
} from '@/components/vault/form/strength-meter';
import {
    DEFAULT_SETTINGS,
    LENGTH_MAX,
    LENGTH_MIN,
    WORDS_MAX,
    WORDS_MIN,
    bitsLabel,
    generate,
    generatorBits,
    scoreFromBits,
} from '@/lib/password-generator';
import type {
    GeneratorMode,
    GeneratorSettings,
} from '@/lib/password-generator';

type GeneratorPopoverProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** "Use this password": fills the field. */
    onUse: (value: string) => void;
};

const MODES: { value: GeneratorMode; label: string }[] = [
    { value: 'random', label: 'Random' },
    { value: 'passphrase', label: 'Passphrase' },
];

/** Range input with its label and value (`cf-field` › `cf-kv` + `.rng`). */
function RangeField({
    label,
    value,
    min,
    max,
    unit,
    onChange,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    unit: string;
    onChange: (value: number) => void;
}) {
    const id = useId();

    return (
        <div className="cf-field">
            <div className="cf-kv">
                <label className="cf-label" htmlFor={id}>
                    {label}
                </label>
                <span className="t-money-sm" aria-hidden="true">
                    {value}
                </span>
            </div>
            <input
                id={id}
                className="rng"
                type="range"
                min={min}
                max={max}
                step={1}
                value={value}
                aria-valuetext={`${value} ${unit}`}
                onChange={(event) =>
                    onChange(Number(event.currentTarget.value))
                }
            />
        </div>
    );
}

/**
 * The password generator popover (VaultAddLogin.dc.html): Random (12–64 characters,
 * symbols, numbers) or Passphrase (3–8 words, capitalise, add a number), with the
 * value's zxcvbn strength and entropy. The value only lives here until it is used.
 */
export function GeneratorPopover({
    open,
    onOpenChange,
    onUse,
}: GeneratorPopoverProps) {
    const [settings, setSettings] =
        useState<GeneratorSettings>(DEFAULT_SETTINGS);
    // Bumped by "Make another".
    const [nonce, setNonce] = useState(0);
    // A fresh value whenever the popover opens, a setting changes or another is asked
    // for; nothing is kept once it closes.
    const value = useMemo(
        () => (open && nonce >= 0 ? generate(settings) : ''),
        [open, settings, nonce],
    );
    const score = useStrength(value, open);
    const look = strengthLook(
        value === '' ? null : (score ?? scoreFromBits(generatorBits(settings))),
    );

    const change = (patch: Partial<GeneratorSettings>) =>
        setSettings((current) => ({ ...current, ...patch }));

    const isPhrase = settings.mode === 'passphrase';

    return (
        <Popover
            open={open}
            onOpenChange={onOpenChange}
            title="Generate password"
            closeLabel="Close generator"
            side="bottom"
            align="end"
            className="vf-gen"
            trigger={
                <Button size="sm" variant="secondary" icon={WandSparkles}>
                    Generate
                </Button>
            }
        >
            <SegmentedControl
                aria-label="Kind"
                size="sm"
                options={MODES}
                value={settings.mode}
                onValueChange={(mode) => change({ mode })}
            />
            <div className="cf-inset vf-gen-box">
                <span className="gen-out vf-gen-value" aria-live="polite">
                    {value}
                </span>
                <IconButton
                    icon={RefreshCw}
                    size="sm"
                    iconSize={14}
                    aria-label="Make another"
                    onClick={() => setNonce((n) => n + 1)}
                />
            </div>
            <div className="cf-row vf-gen-meter">
                <StrengthDots look={look} />
                <StrengthLabel look={look} />
                <span className="t-caption t-muted">{bitsLabel(settings)}</span>
            </div>
            {isPhrase ? (
                <>
                    <RangeField
                        label="Words"
                        unit="words"
                        value={settings.words}
                        min={WORDS_MIN}
                        max={WORDS_MAX}
                        onChange={(words) => change({ words })}
                    />
                    <div className="vf-gen-switches">
                        <Toggle
                            checked={settings.caps}
                            onCheckedChange={(caps) => change({ caps })}
                        >
                            Capitalise
                        </Toggle>
                        <Toggle
                            checked={settings.digits}
                            onCheckedChange={(digits) => change({ digits })}
                        >
                            Add a number
                        </Toggle>
                    </div>
                </>
            ) : (
                <>
                    <RangeField
                        label="Length"
                        unit="characters"
                        value={settings.length}
                        min={LENGTH_MIN}
                        max={LENGTH_MAX}
                        onChange={(length) => change({ length })}
                    />
                    <div className="vf-gen-switches">
                        <Toggle
                            checked={settings.symbols}
                            onCheckedChange={(symbols) => change({ symbols })}
                        >
                            Symbols
                        </Toggle>
                        <Toggle
                            checked={settings.digits}
                            onCheckedChange={(digits) => change({ digits })}
                        >
                            Numbers
                        </Toggle>
                    </div>
                </>
            )}
            <Button
                variant="primary"
                onClick={() => {
                    onUse(value);
                    onOpenChange(false);
                }}
            >
                Use this password
            </Button>
        </Popover>
    );
}
