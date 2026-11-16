import { X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { FieldMessage, useFieldIds } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export const MAX_TAGS = 12;
export const MAX_TAG_LENGTH = 32;

/** Lower-cased, trimmed, and only when new and there's room. */
export function addTag(tags: string[], draft: string): string[] {
    const tag = draft.trim().toLowerCase().slice(0, MAX_TAG_LENGTH);

    if (tag === '' || tags.includes(tag) || tags.length >= MAX_TAGS) {
        return tags;
    }

    return [...tags, tag];
}

/** Tags (VaultAddLogin.dc.html): chips with remove, plus "Add a tag and press Enter". */
export function TagInput({
    tags,
    onChange,
    error,
}: {
    tags: string[];
    onChange: (tags: string[]) => void;
    error?: ReactNode;
}) {
    const [draft, setDraft] = useState('');
    const full = tags.length >= MAX_TAGS;
    const ids = useFieldIds(undefined, {
        help: full ? `Up to ${MAX_TAGS} tags.` : undefined,
        error,
    });

    const commit = () => {
        onChange(addTag(tags, draft));
        setDraft('');
    };

    return (
        <div className="cf-field">
            <label className="cf-label" htmlFor={ids.id}>
                Tags
            </label>
            {tags.length > 0 && (
                <div className="cf-row vf-tags" role="list" aria-label="Tags">
                    {tags.map((tag) => (
                        <span key={tag} className="cf-chip" role="listitem">
                            {tag}
                            <button
                                type="button"
                                className="cf-chip-x"
                                aria-label={`Remove tag ${tag}`}
                                onClick={() =>
                                    onChange(tags.filter((t) => t !== tag))
                                }
                            >
                                <Icon icon={X} size={12} strokeWidth={2.5} />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            <span
                className={cn(
                    'cf-inwrap cf-inwrap--sm',
                    ids.invalid && 'is-error',
                    full && 'is-disabled',
                )}
            >
                <input
                    id={ids.id}
                    type="text"
                    value={draft}
                    maxLength={MAX_TAG_LENGTH}
                    disabled={full}
                    placeholder="Add a tag and press Enter"
                    aria-invalid={ids.invalid || undefined}
                    aria-describedby={ids.describedBy}
                    onChange={(event) => setDraft(event.currentTarget.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commit();
                        } else if (
                            event.key === 'Backspace' &&
                            draft === '' &&
                            tags.length > 0
                        ) {
                            onChange(tags.slice(0, -1));
                        }
                    }}
                />
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={full || draft.trim() === ''}
                    onClick={commit}
                >
                    Add
                </Button>
            </span>
            <FieldMessage
                ids={ids}
                help={full ? `Up to ${MAX_TAGS} tags.` : undefined}
                error={error}
            />
        </div>
    );
}
