import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import { Toggle } from '@/components/ui/toggle';
import { rowKey } from '@/components/vault/form/form-state';
import type { CustomRow } from '@/components/vault/form/form-state';
import { savedPlaceholder } from '@/components/vault/form/secret-input';
import { cn } from '@/lib/utils';

export const MAX_CUSTOM_FIELDS = 30;

type CustomFieldsProps = {
    rows: CustomRow[];
    onChange: (rows: CustomRow[]) => void;
    /** Server errors by key (`custom_fields.0.label`). */
    errors: Record<string, string | undefined>;
};

/**
 * Custom fields (spec §14.1 "custom fields, each marked secret or not"): label, value,
 * a Secret switch and remove. A stored secret is never sent back to the form: its
 * value field stays empty ("Saved · type to replace"), and removing the row deletes it.
 */
export function CustomFields({ rows, onChange, errors }: CustomFieldsProps) {
    const update = (key: string, patch: Partial<CustomRow>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));

    return (
        <section className="cf-field vf-custom" aria-labelledby="vf-custom-h">
            <span id="vf-custom-h" className="cf-label">
                Custom fields
            </span>
            {rows.length === 0 && (
                <span className="cf-help">
                    Anything the template doesn’t cover: an account ID, a
                    security answer, a PIN.
                </span>
            )}
            {rows.map((row, i) => {
                const labelError = errors[`custom_fields.${i}.label`];
                const valueError = errors[`custom_fields.${i}.value`];
                const name = row.label.trim() || `field ${i + 1}`;

                return (
                    <div key={row.key} className="vf-custom-row">
                        <input
                            className={cn(
                                'cf-input cf-input--sm',
                                labelError && 'is-error',
                            )}
                            type="text"
                            value={row.label}
                            maxLength={60}
                            placeholder="Label"
                            aria-label={`Label of custom field ${i + 1}`}
                            aria-invalid={labelError ? true : undefined}
                            onChange={(event) =>
                                update(row.key, {
                                    label: event.currentTarget.value,
                                })
                            }
                        />
                        <input
                            className={cn(
                                'cf-input cf-input--sm',
                                row.secret && 'vf-mono',
                                valueError && 'is-error',
                            )}
                            type={row.secret ? 'password' : 'text'}
                            value={row.value}
                            maxLength={5000}
                            autoComplete={row.secret ? 'new-password' : 'off'}
                            spellCheck={false}
                            placeholder={savedPlaceholder(
                                { saved: row.secret && row.set },
                                'Value',
                            )}
                            aria-label={`Value of ${name}`}
                            aria-invalid={valueError ? true : undefined}
                            onChange={(event) =>
                                update(row.key, {
                                    value: event.currentTarget.value,
                                })
                            }
                        />
                        <Toggle
                            checked={row.secret}
                            onCheckedChange={(secret) =>
                                // A plain value typed before turning Secret on stays; a
                                // stored secret can't be shown, so switching off starts empty.
                                update(row.key, {
                                    secret,
                                    value: !secret && row.set ? '' : row.value,
                                })
                            }
                        >
                            Secret
                        </Toggle>
                        <IconButton
                            icon={Trash2}
                            size="sm"
                            variant="bare"
                            iconSize={16}
                            aria-label={`Remove ${name}`}
                            onClick={() =>
                                onChange(rows.filter((r) => r.key !== row.key))
                            }
                        />
                        {(labelError || valueError) && (
                            <FieldError className="vf-custom-err">
                                {labelError ?? valueError}
                            </FieldError>
                        )}
                    </div>
                );
            })}
            <div>
                <Button
                    variant="ghost"
                    size="sm"
                    icon={Plus}
                    disabled={rows.length >= MAX_CUSTOM_FIELDS}
                    onClick={() =>
                        onChange([
                            ...rows,
                            {
                                key: rowKey(),
                                id: null,
                                label: '',
                                secret: false,
                                value: '',
                                set: false,
                            },
                        ])
                    }
                >
                    Add a field
                </Button>
            </div>
        </section>
    );
}
