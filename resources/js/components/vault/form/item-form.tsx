import { Head, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Button, ButtonLink } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Field, useFieldIds } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { Stepper } from '@/components/ui/stepper';
import { TextInput } from '@/components/ui/text-input';
import { Textarea } from '@/components/ui/textarea';
import { Toggle } from '@/components/ui/toggle';
import { AttachmentsField } from '@/components/vault/form/attachments-field';
import { CustomFields } from '@/components/vault/form/custom-fields';
import {
    ENVIRONMENTS,
    NOTES_KEY,
    buildPayload,
    initialState,
    scoredField,
    wipeSecrets,
} from '@/components/vault/form/form-state';
import type { FormState } from '@/components/vault/form/form-state';
import { GeneratorPopover } from '@/components/vault/form/generator-popover';
import {
    SavedSecretAction,
    SecretInput,
    savedPlaceholder,
} from '@/components/vault/form/secret-input';
import {
    PreviewCard,
    SecurityCheckCard,
} from '@/components/vault/form/side-cards';
import {
    loadStrength,
    strengthLook,
    useStrengthResult,
} from '@/components/vault/form/strength';
import type { StrengthLook } from '@/components/vault/form/strength';
import {
    StrengthDots,
    StrengthLabel,
} from '@/components/vault/form/strength-meter';
import { TagInput } from '@/components/vault/form/tag-input';
import { TotpInput } from '@/components/vault/form/totp-input';
import { totpKeyState } from '@/lib/totp';
import { cn } from '@/lib/utils';
import { index as vaultIndex } from '@/routes/vault';
import { create, store, update } from '@/routes/vault/items';
import type {
    VaultCreateProps,
    VaultEnvironment,
    VaultTemplateField,
} from '@/types/vault';

/** "Website login" → "website login"; "API key / token" stays. */
const sentenceCase = (label: string): string =>
    /^[A-Z][a-z]/.test(label)
        ? label.charAt(0).toLowerCase() + label.slice(1)
        : label;

const CHECKING: StrengthLook = {
    ...strengthLook(null),
    label: 'Checking',
    help: '',
};

/** A plain input inside `cf-inwrap` with a leading icon (the board's URL field). */
function IconTextField({
    id,
    label,
    value,
    placeholder,
    error,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    placeholder?: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    const ids = useFieldIds(id, { error });

    return (
        <Field ids={ids} label={label} error={error}>
            <span className={cn('cf-inwrap', ids.invalid && 'is-error')}>
                <Icon icon={Globe} size={16} />
                <input
                    id={ids.id}
                    type="text"
                    inputMode="url"
                    value={value}
                    placeholder={placeholder}
                    autoComplete="off"
                    spellCheck={false}
                    aria-invalid={ids.invalid || undefined}
                    aria-describedby={ids.describedBy}
                    onChange={(event) => onChange(event.currentTarget.value)}
                />
            </span>
        </Field>
    );
}

type ItemFormProps = VaultCreateProps;

/**
 * Add item, step 2, and Edit (VaultAddLogin.dc.html; spec §14.4): the type's template
 * form plus vault, environment, name, tags, notes, expiry, custom fields and
 * attachments, with the generator, live strength, the TOTP preview, a Preview card and
 * a Security check. Secrets are never prefilled; an empty secret keeps the stored one.
 */
export function ItemForm({
    templates,
    vaults,
    canRestrict,
    attachmentMaxKb,
    generatorMinLength,
    provider,
    type: typeProp,
    item,
    defaultVaultId,
    rotate,
}: ItemFormProps) {
    const type = item?.type ?? typeProp ?? 'login';
    const template = templates.find((t) => t.type === type);
    const editing = item !== null;
    const isCustom = provider === null || provider.key === 'custom';
    const [state, setState] = useState<FormState>(() =>
        initialState({ template, provider, item, defaultVaultId, vaults }),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<false | 'save' | 'share'>(false);
    const [genOpen, setGenOpen] = useState(rotate);
    const [shownPrimary, setShownPrimary] = useState(false);
    const nameRef = useRef<HTMLInputElement>(null);
    const stateRef = useRef(state);

    useEffect(() => {
        stateRef.current = state;
    }, [state]);

    const scored = scoredField(template);
    const primaryValue = scored ? (state.secrets[scored.key] ?? '') : '';
    const strength = useStrengthResult(primaryValue);
    const setKeys = new Set(item?.secretsSet ?? []);

    // Typed secrets live only in this component: wipe them when the page goes.
    useEffect(
        () => () => {
            wipeSecrets(stateRef.current);
        },
        [],
    );

    // New items: focus the name with the cursor after "GitLab — ". Rotate: the
    // generator opens instead (and takes focus).
    useEffect(() => {
        if (editing || rotate) {
            return;
        }

        const input = nameRef.current;

        if (input) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    }, [editing, rotate]);

    // Rotate on a type without a generated password (API key, licence…): focus the
    // main secret so a new one can be pasted.
    const primaryKey = template?.fields.find((f) => f.primary === true)?.key;
    const hasGenerator = scored !== undefined;

    useEffect(() => {
        if (!rotate) {
            return;
        }

        if (hasGenerator) {
            void loadStrength().catch(() => undefined);
        } else if (primaryKey) {
            document.getElementById(`vf-f-${primaryKey}`)?.focus();
        }
    }, [rotate, hasGenerator, primaryKey]);

    // After a failed save, move to the first field with an error.
    useEffect(() => {
        if (Object.keys(errors).length === 0) {
            return;
        }

        document
            .querySelector<HTMLElement>(
                '.vf-form [aria-invalid="true"], .vf-form .cf-drop.is-error input',
            )
            ?.focus();
    }, [errors]);

    const patch = (next: Partial<FormState>) =>
        setState((current) => ({ ...current, ...next }));
    const setField = (key: string, value: string | boolean) =>
        setState((current) => ({
            ...current,
            fields: { ...current.fields, [key]: value },
        }));
    const setSecret = (key: string, value: string) =>
        setState((current) => ({
            ...current,
            secrets: { ...current.secrets, [key]: value },
        }));
    const clearProps = (key: string) => ({
        saved: setKeys.has(key),
        cleared: state.clear.includes(key),
        onClear: () =>
            setState((current) => ({
                ...current,
                clear: [...current.clear.filter((k) => k !== key), key],
                secrets: { ...current.secrets, [key]: '' },
            })),
        onUndoClear: () =>
            setState((current) => ({
                ...current,
                clear: current.clear.filter((k) => k !== key),
            })),
    });

    const submit = (share: boolean) => {
        if (busy) {
            return;
        }

        const payload = buildPayload(state, {
            type,
            provider: item ? item.provider : (provider?.key ?? null),
            template,
            canRestrict,
            strength: strength?.score ?? null,
            share,
        });
        const options = {
            forceFormData: true,
            queryStringArrayFormat: 'indices' as const,
            preserveScroll: true,
            onStart: () => setBusy(share ? 'share' : 'save'),
            onError: (next: Record<string, string>) => setErrors(next),
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        };

        if (item) {
            router.post(
                update(item.id).url,
                { ...payload, _method: 'put' },
                options,
            );
        } else {
            router.post(store().url, payload, options);
        }
    };

    const backHref = item
        ? vaultIndex({ query: { item: item.id } }).url
        : create({ query: { vault: state.vaultId } }).url;
    const typeLabel = template?.label ?? 'Item';
    const title = item
        ? `Edit ${item.name}`
        : provider && !isCustom && type === 'login'
          ? `Add ${provider.name} login`
          : `Add ${sentenceCase(typeLabel)}`;
    const stepOne = provider && !isCustom ? provider.name : typeLabel;
    const vaultName =
        vaults.find((v) => String(v.id) === state.vaultId)?.name ?? '';
    const category = provider && !isCustom ? provider.category : typeLabel;
    const totpValue = state.secrets.totp ?? '';
    const hasTotp =
        totpKeyState(totpValue) === 'ok' ||
        (totpValue === '' &&
            setKeys.has('totp') &&
            !state.clear.includes('totp'));

    const renderPrimary = (field: VaultTemplateField) => {
        const typed = primaryValue !== '';
        const cleared = state.clear.includes(field.key);
        const saved = setKeys.has(field.key);
        const look: StrengthLook = typed
            ? strength
                ? strengthLook(strength.score)
                : CHECKING
            : saved && !cleared
              ? {
                    ...strengthLook(item?.strength ?? null),
                    label:
                        item?.strength === null || item?.strength === undefined
                            ? 'Saved'
                            : strengthLook(item.strength).label,
                    help: 'Saved. Type or generate a new one to replace it.',
                }
              : cleared
                ? {
                      ...strengthLook(null),
                      help: 'The saved password is removed when you save.',
                  }
                : strengthLook(null);

        return (
            <SecretInput
                key={field.key}
                id={`vf-f-${field.key}`}
                fieldClassName="vf-wide vf-primary"
                label={field.label}
                value={primaryValue}
                onValueChange={(value) => setSecret(field.key, value)}
                onFocus={() => void loadStrength().catch(() => undefined)}
                shown={shownPrimary}
                onShownChange={setShownPrimary}
                aside={<StrengthLabel look={look} />}
                addon={
                    <GeneratorPopover
                        open={genOpen}
                        minLength={generatorMinLength}
                        onOpenChange={(open) => {
                            setGenOpen(open);

                            if (open) {
                                void loadStrength().catch(() => undefined);
                            }
                        }}
                        onUse={(value) => {
                            setSecret(field.key, value);
                            setShownPrimary(true);
                        }}
                    />
                }
                below={<StrengthDots look={look} className="vf-dots" />}
                help={look.help}
                error={errors[`secrets.${field.key}`]}
                {...clearProps(field.key)}
            />
        );
    };

    const renderField = (field: VaultTemplateField): ReactNode => {
        const id = `vf-f-${field.key}`;
        const wide = field.wide ? 'vf-wide' : undefined;

        if (field.secret) {
            if (scored && field.key === scored.key) {
                return renderPrimary(field);
            }

            if (field.kind === 'totp') {
                return (
                    <TotpInput
                        key={field.key}
                        id={id}
                        fieldClassName="vf-wide"
                        label={field.label}
                        value={totpValue}
                        onValueChange={(value) => setSecret(field.key, value)}
                        providerName={
                            provider && !isCustom ? provider.name : null
                        }
                        error={errors[`secrets.${field.key}`]}
                        {...clearProps(field.key)}
                    />
                );
            }

            return (
                <SecretInput
                    key={field.key}
                    id={id}
                    fieldClassName={wide}
                    label={field.label}
                    multiline={field.kind === 'secret_text'}
                    placeholder={field.placeholder}
                    value={state.secrets[field.key] ?? ''}
                    onValueChange={(value) => setSecret(field.key, value)}
                    error={errors[`secrets.${field.key}`]}
                    {...clearProps(field.key)}
                />
            );
        }

        const value = state.fields[field.key];
        const text = typeof value === 'string' ? value : '';
        const error = errors[`fields.${field.key}`];

        switch (field.kind) {
            case 'url':
                return (
                    <div key={field.key} className={cn('vf-cell', wide)}>
                        <IconTextField
                            id={id}
                            label={field.label}
                            value={text}
                            placeholder={field.placeholder}
                            error={error}
                            onChange={(v) => setField(field.key, v)}
                        />
                    </div>
                );
            case 'select':
                return (
                    <Select
                        key={field.key}
                        id={id}
                        fieldClassName={wide}
                        label={field.label}
                        value={text}
                        placeholder="Choose"
                        options={(field.options ?? []).map((o) => ({
                            value: o,
                            label: o,
                        }))}
                        error={error}
                        onChange={(event) =>
                            setField(field.key, event.currentTarget.value)
                        }
                    />
                );
            case 'textarea':
                return (
                    <Textarea
                        key={field.key}
                        id={id}
                        fieldClassName={wide}
                        label={field.label}
                        rows={3}
                        value={text}
                        placeholder={field.placeholder}
                        error={error}
                        onChange={(event) =>
                            setField(field.key, event.currentTarget.value)
                        }
                    />
                );
            case 'toggle':
                return (
                    <div
                        key={field.key}
                        className={cn('cf-field vf-toggle-cell', wide)}
                    >
                        <Toggle
                            id={id}
                            checked={value === true}
                            error={error}
                            onCheckedChange={(on) => setField(field.key, on)}
                        >
                            {field.label}
                        </Toggle>
                    </div>
                );
            default:
                return (
                    <TextInput
                        key={field.key}
                        id={id}
                        fieldClassName={wide}
                        label={field.label}
                        type={field.kind === 'email' ? 'email' : 'text'}
                        inputMode={
                            field.kind === 'number' ? 'numeric' : undefined
                        }
                        autoComplete="off"
                        value={text}
                        placeholder={field.placeholder}
                        error={error}
                        onChange={(event) =>
                            setField(field.key, event.currentTarget.value)
                        }
                    />
                );
        }
    };

    const notesIds = {
        id: 'vf-f-notes',
        helpId: 'vf-f-notes-help',
        errorId: 'vf-f-notes-error',
    };
    const notesError = errors[`secrets.${NOTES_KEY}`];
    const notesValue = state.secrets[NOTES_KEY] ?? '';
    const notesClear = clearProps(NOTES_KEY);
    const attachmentError =
        errors.attachments ??
        Object.entries(errors).find(([k]) => k.startsWith('attachments.'))?.[1];

    return (
        <>
            <Head title={item ? `Edit ${item.name}` : 'Add item'} />
            <PageHeader
                kicker="Vault"
                title={title}
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: item ? 'Edit item' : 'Add item' },
                ]}
                actions={
                    <>
                        <ButtonLink
                            href={backHref}
                            variant="secondary"
                            size="lg"
                            icon={ArrowLeft}
                        >
                            Back
                        </ButtonLink>
                        <Button
                            variant="muted"
                            size="lg"
                            loading={busy === 'save'}
                            disabled={busy !== false}
                            onClick={() => submit(false)}
                        >
                            Save
                        </Button>
                        <Button
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={busy === 'share'}
                            disabled={busy !== false}
                            onClick={() => submit(true)}
                        >
                            Save and share
                        </Button>
                    </>
                }
            />

            {!editing && (
                <Stepper
                    aria-label="Add item steps"
                    steps={[stepOne, 'Details', 'Share']}
                    current={1}
                />
            )}

            <div className="vf-layout">
                <form
                    className="cf-card vf-form"
                    aria-labelledby="vf-fm-h"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit(false);
                    }}
                >
                    <h2 id="vf-fm-h" className="cf-dot-title t-title">
                        {typeLabel}
                    </h2>
                    <div className="vf-grid">
                        <Select
                            id="vf-vault"
                            label="Vault"
                            value={state.vaultId}
                            options={vaults.map((v) => ({
                                value: String(v.id),
                                label: v.name,
                            }))}
                            error={errors.vault_id}
                            onChange={(event) =>
                                patch({ vaultId: event.currentTarget.value })
                            }
                        />
                        <div className="vf-cell">
                            <SegmentedControl<VaultEnvironment | ''>
                                label="Environment"
                                options={ENVIRONMENTS}
                                value={state.environment}
                                onValueChange={(environment) =>
                                    patch({ environment })
                                }
                            />
                            {errors.environment && (
                                <span className="cf-errtext">
                                    {errors.environment}
                                </span>
                            )}
                        </div>
                        <TextInput
                            ref={nameRef}
                            id="vf-name"
                            fieldClassName="vf-wide"
                            label="Name"
                            placeholder="Hostinger · main account"
                            value={state.name}
                            maxLength={160}
                            autoComplete="off"
                            error={errors.name}
                            onChange={(event) =>
                                patch({ name: event.currentTarget.value })
                            }
                        />

                        {template?.fields.map(renderField)}

                        <DatePicker
                            id="vf-expires"
                            label={template?.expiryLabel ?? 'Expires on'}
                            value={state.expiresAt}
                            onValueChange={(expiresAt) => patch({ expiresAt })}
                            help="Optional. Coffer reminds you 30, 7 and 1 days before."
                            error={errors.expires_at}
                        />
                        {canRestrict && (
                            <div className="cf-field vf-toggle-cell">
                                <span className="cf-label" aria-hidden="true">
                                    Access
                                </span>
                                <Toggle
                                    id="vf-restricted"
                                    checked={state.restricted}
                                    onCheckedChange={(restricted) =>
                                        patch({ restricted })
                                    }
                                    help="Vault access doesn’t reach it. Only admins and people you share it with can open it."
                                    error={errors.restricted}
                                >
                                    Admins only
                                </Toggle>
                            </div>
                        )}

                        <TagInput
                            tags={state.tags}
                            onChange={(tags) => patch({ tags })}
                            error={
                                errors.tags ??
                                Object.entries(errors).find(([k]) =>
                                    k.startsWith('tags.'),
                                )?.[1]
                            }
                        />
                        <Field
                            ids={notesIds}
                            label="Notes"
                            labelAction={
                                <SavedSecretAction
                                    {...notesClear}
                                    typed={notesValue !== ''}
                                    label="Notes"
                                />
                            }
                            error={notesError}
                        >
                            <textarea
                                id={notesIds.id}
                                className="cf-textarea"
                                rows={3}
                                value={notesValue}
                                placeholder={savedPlaceholder(notesClear)}
                                aria-invalid={notesError ? true : undefined}
                                aria-describedby={
                                    notesError ? notesIds.errorId : undefined
                                }
                                onChange={(event) =>
                                    setSecret(
                                        NOTES_KEY,
                                        event.currentTarget.value,
                                    )
                                }
                            />
                        </Field>

                        <div className="vf-wide">
                            <CustomFields
                                rows={state.custom}
                                onChange={(custom) => patch({ custom })}
                                errors={errors}
                            />
                        </div>

                        <div className="vf-wide">
                            <AttachmentsField
                                existing={(item?.attachments ?? []).filter(
                                    (a) =>
                                        !state.removeAttachments.includes(a.id),
                                )}
                                onRemoveExisting={(id) =>
                                    patch({
                                        removeAttachments: [
                                            ...state.removeAttachments,
                                            id,
                                        ],
                                    })
                                }
                                files={state.attachments}
                                onFilesChange={(attachments) =>
                                    patch({ attachments })
                                }
                                maxKb={attachmentMaxKb}
                                error={attachmentError}
                            />
                        </div>
                    </div>
                </form>

                <aside className="vf-aside" aria-label="Preview and checks">
                    <PreviewCard
                        monogram={
                            provider && !isCustom ? provider.monogram : null
                        }
                        name={state.name}
                        vault={vaultName}
                        category={category}
                        environment={state.environment}
                        tags={state.tags}
                    />
                    <SecurityCheckCard
                        scored={scored !== undefined}
                        password={primaryValue}
                        score={strength?.score ?? null}
                        bits={strength?.bits ?? null}
                        savedScore={
                            scored &&
                            setKeys.has(scored.key) &&
                            !state.clear.includes(scored.key)
                                ? (item?.strength ?? null)
                                : null
                        }
                        showTotp={type === 'login'}
                        hasTotp={hasTotp}
                        itemId={item?.id ?? null}
                    />
                </aside>
            </div>
        </>
    );
}
