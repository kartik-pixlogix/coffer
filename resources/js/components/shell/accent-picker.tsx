import { router, usePage } from '@inertiajs/react';
import { Check, Palette, TriangleAlert } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Popover } from '@/components/ui/popover';
import { Sheet } from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { useToast } from '@/components/ui/toast';
import {
    ACCENT_PRESETS,
    CUSTOM,
    DEFAULT_PRESET,
    MIN_CONTRAST,
    accentPayload,
    applyAccent,
    gradientCss,
    minContrast,
    onColor,
    presetChoice,
} from '@/lib/accent';
import type { AccentChoice, AccentStops } from '@/lib/accent';
import { update as updateAccent } from '@/routes/accent';

const STOP_LABELS = ['Start', 'Middle', 'End'] as const;
const LIME = ACCENT_PRESETS[0];

/** The saved gradient, a working copy for the picker, and save / cancel. */
function useAccentDraft() {
    const saved = usePage().props.auth.user.accent;
    const savedRef = useRef<AccentChoice>(saved);
    const [draft, setDraft] = useState<AccentChoice>(saved);
    const [saving, setSaving] = useState(false);
    const toast = useToast();
    const key = `${saved.preset}|${saved.stops.join()}|${saved.angle}`;

    savedRef.current = saved;

    // A save in another tab (or a fresh page) keeps the head style in step.
    useEffect(() => applyAccent(savedRef.current), [key]);

    const change = (next: AccentChoice) => {
        setDraft(next);
        applyAccent(next);
    };

    const start = () => setDraft(savedRef.current);
    const cancel = () => applyAccent(savedRef.current);

    const save = (done: () => void) => {
        setSaving(true);
        router.patch(updateAccent.url(), accentPayload(draft), {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
            onSuccess: () => {
                toast.success('Colours saved');
                done();
            },
            onError: () =>
                toast.error('Those colours couldn’t be saved. Try again.'),
            onFinish: () => setSaving(false),
        });
    };

    return { draft, change, start, cancel, save, saving };
}

/**
 * The picker body (D147): preset gradients as a radio group, then "Custom" with three
 * colours and an angle, and a preview card that warns when text on it is hard to read.
 */
function AccentEditor({
    value,
    onChange,
}: {
    value: AccentChoice;
    onChange: (next: AccentChoice) => void;
}) {
    const name = useId();
    const angleId = useId();
    const isCustom = value.preset === CUSTOM;
    // The last custom colours, so going back to Custom after a preset keeps them.
    const [custom, setCustom] = useState<AccentChoice>(
        isCustom ? value : { ...presetChoice(LIME), preset: CUSTOM },
    );
    const text = onColor(value.stops);
    const readable = minContrast(value.stops, text) >= MIN_CONTRAST;

    const editCustom = (next: Partial<AccentChoice>) => {
        const updated = { ...custom, ...next, preset: CUSTOM };

        setCustom(updated);
        onChange(updated);
    };

    const setStop = (index: number, color: string) => {
        const stops = [...custom.stops] as AccentStops;

        stops[index] = color.toLowerCase();
        editCustom({ stops });
    };

    return (
        <div className="accent-editor">
            <fieldset className="accent-group">
                <legend className="cf-label">Gradient</legend>
                <div className="accent-grid">
                    {ACCENT_PRESETS.map((preset) => (
                        <label key={preset.key} className="accent-swatch">
                            <input
                                className="cf-sr"
                                type="radio"
                                name={name}
                                value={preset.key}
                                checked={value.preset === preset.key}
                                onChange={() => onChange(presetChoice(preset))}
                            />
                            <span
                                className="accent-disc"
                                style={{
                                    background: gradientCss(preset.stops, 135),
                                    color: onColor(preset.stops),
                                }}
                                aria-hidden="true"
                            >
                                {value.preset === preset.key && (
                                    <Icon icon={Check} size={16} />
                                )}
                            </span>
                            <span className="t-caption accent-name">
                                {preset.name}
                            </span>
                        </label>
                    ))}
                    <label className="accent-swatch">
                        <input
                            className="cf-sr"
                            type="radio"
                            name={name}
                            value={CUSTOM}
                            checked={isCustom}
                            onChange={() => onChange(custom)}
                        />
                        <span
                            className="accent-disc accent-disc--custom"
                            style={{
                                background: gradientCss(
                                    custom.stops,
                                    custom.angle,
                                ),
                                color: onColor(custom.stops),
                            }}
                            aria-hidden="true"
                        >
                            {isCustom ? (
                                <Icon icon={Check} size={16} />
                            ) : (
                                <Icon icon={Palette} size={16} />
                            )}
                        </span>
                        <span className="t-caption accent-name">Custom</span>
                    </label>
                </div>
            </fieldset>

            {isCustom && (
                <div className="accent-custom">
                    <div className="accent-stops">
                        {STOP_LABELS.map((label, index) => (
                            <label key={label} className="cf-field">
                                <span className="cf-label">{label}</span>
                                <span className="accent-color">
                                    <input
                                        type="color"
                                        value={custom.stops[index]}
                                        onChange={(e) =>
                                            setStop(index, e.target.value)
                                        }
                                    />
                                    <span className="t-caption t-muted t-mono">
                                        {custom.stops[index]}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <div className="cf-field">
                        <label className="cf-label-row" htmlFor={angleId}>
                            <span className="cf-label">Angle</span>
                            <span className="t-caption t-muted t-mono">
                                {custom.angle}°
                            </span>
                        </label>
                        <input
                            id={angleId}
                            className="accent-angle"
                            type="range"
                            min={0}
                            max={360}
                            step={15}
                            value={custom.angle}
                            onChange={(e) =>
                                editCustom({ angle: Number(e.target.value) })
                            }
                        />
                    </div>
                </div>
            )}

            <div
                className="accent-preview"
                style={{
                    background: gradientCss(value.stops, value.angle),
                    color: text,
                }}
            >
                <span className="t-caption">Current balance</span>
                <span className="t-money-lg">₹12,34,567</span>
            </div>
            {!readable && (
                <StatusPill tone="warn" icon={TriangleAlert}>
                    Text may be hard to read. Try lighter colours.
                </StatusPill>
            )}
        </div>
    );
}

function Actions({
    onReset,
    onCancel,
    onSave,
    saving,
    isDefault,
}: {
    onReset: () => void;
    onCancel: () => void;
    onSave: () => void;
    saving: boolean;
    isDefault: boolean;
}) {
    return (
        <div className="accent-actions">
            <Button
                variant="ghost"
                size="sm"
                onClick={onReset}
                disabled={isDefault}
            >
                Reset to lime
            </Button>
            <span className="flex-1" />
            <Button variant="secondary" size="sm" onClick={onCancel}>
                Cancel
            </Button>
            <Button
                variant="primary"
                size="sm"
                onClick={onSave}
                loading={saving}
            >
                Save
            </Button>
        </div>
    );
}

/** Top-bar button and popover for the brand gradient (D147). */
export function AccentPicker() {
    const [open, setOpen] = useState(false);
    const { draft, change, start, cancel, save, saving } = useAccentDraft();

    const onOpenChange = (next: boolean) => {
        if (next) {
            start();
        } else {
            cancel();
        }

        setOpen(next);
    };

    return (
        <Popover
            title="Brand colours"
            open={open}
            onOpenChange={onOpenChange}
            align="end"
            className="accent-pop"
            trigger={
                <IconButton
                    icon={Palette}
                    size="lg"
                    aria-label="Brand colours"
                    aria-haspopup="dialog"
                />
            }
        >
            <AccentEditor value={draft} onChange={change} />
            <Actions
                isDefault={draft.preset === DEFAULT_PRESET}
                onReset={() => change(presetChoice(LIME))}
                onCancel={() => onOpenChange(false)}
                onSave={() => save(() => setOpen(false))}
                saving={saving}
            />
        </Popover>
    );
}

/** The phone menu's "Brand colours" row: the same picker in a bottom sheet. */
export function PhoneAccentRow() {
    const [open, setOpen] = useState(false);
    const { draft, change, start, cancel, save, saving } = useAccentDraft();

    const onOpenChange = (next: boolean) => {
        if (next) {
            start();
        } else {
            cancel();
        }

        setOpen(next);
    };

    return (
        <>
            <button
                type="button"
                className="app-menu-item"
                onClick={() => onOpenChange(true)}
            >
                <span className="app-menu-ic">
                    <Icon icon={Palette} size={16} />
                </span>
                <span className="t-body flex-1 font-medium">Brand colours</span>
                <span
                    className="accent-disc accent-disc--sm"
                    style={{
                        background: gradientCss(draft.stops, draft.angle),
                    }}
                    aria-hidden="true"
                />
            </button>
            <Sheet
                open={open}
                onOpenChange={onOpenChange}
                title="Brand colours"
                raised
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
                            variant="primary"
                            size="lg"
                            onClick={() => save(() => setOpen(false))}
                            loading={saving}
                        >
                            Save
                        </Button>
                    </>
                }
            >
                <AccentEditor value={draft} onChange={change} />
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => change(presetChoice(LIME))}
                    disabled={draft.preset === DEFAULT_PRESET}
                >
                    Reset to lime
                </Button>
            </Sheet>
        </>
    );
}
