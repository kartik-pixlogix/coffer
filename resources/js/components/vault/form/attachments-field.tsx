import { FileText, X } from 'lucide-react';
import { useState } from 'react';
import { FieldError } from '@/components/ui/field';
import { FileDrop } from '@/components/ui/file-drop';
import { formatFileSize } from '@/components/ui/file-drop.utils';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import type { VaultAttachmentMeta } from '@/types/vault';

export const MAX_ATTACHMENTS = 10;

type AttachmentsFieldProps = {
    /** Already stored (edit mode), minus the ones marked for removal. */
    existing: VaultAttachmentMeta[];
    onRemoveExisting: (id: number) => void;
    files: File[];
    onFilesChange: (files: File[]) => void;
    maxKb: number;
    /** Server errors: `attachments` or `attachments.N`. */
    error?: string;
};

function AttachedRow({
    name,
    size,
    note,
    onRemove,
}: {
    name: string;
    size: number;
    note: string;
    onRemove: () => void;
}) {
    return (
        <div className="cf-file">
            <Icon icon={FileText} size={18} />
            <span className="cf-file-name">{name}</span>
            <span className="t-caption t-muted vf-file-meta">
                {formatFileSize(size)} · {note}
            </span>
            <IconButton
                icon={X}
                size="sm"
                iconSize={14}
                aria-label={`Remove ${name}`}
                onClick={onRemove}
            />
        </div>
    );
}

/**
 * Attachments 🔒 (spec §14.1): recovery codes, keys or certificates, up to the size
 * limit each and 10 per item. Files upload with the form and are encrypted on save.
 */
export function AttachmentsField({
    existing,
    onRemoveExisting,
    files,
    onFilesChange,
    maxKb,
    error,
}: AttachmentsFieldProps) {
    const [countError, setCountError] = useState<string | null>(null);
    const total = existing.length + files.length;
    const limit =
        maxKb >= 1024 ? `${Math.round(maxKb / 1024)} MB` : `${maxKb} KB`;

    return (
        <div className="cf-field">
            <span className="cf-label" id="vf-att-label">
                {total > 1 ? 'Attachments' : 'Attachment'}
            </span>
            {existing.map((a) => (
                <AttachedRow
                    key={`saved-${a.id}`}
                    name={a.name}
                    size={a.size}
                    note="encrypted"
                    onRemove={() => onRemoveExisting(a.id)}
                />
            ))}
            {files.map((file, i) => (
                <AttachedRow
                    key={`new-${i}-${file.name}`}
                    name={file.name}
                    size={file.size}
                    note="encrypted on save"
                    onRemove={() => {
                        setCountError(null);
                        onFilesChange(files.filter((_, j) => j !== i));
                    }}
                />
            ))}
            {total < MAX_ATTACHMENTS && (
                <FileDrop
                    variant="row"
                    aria-label="Attach a file"
                    title="Attach a file"
                    limits={`recovery codes, keys or certificates, up to ${limit}`}
                    maxSize={maxKb * 1024}
                    multiple
                    rejectHint={`Files can be up to ${limit} each.`}
                    error={error}
                    onFiles={(picked) => {
                        const room = MAX_ATTACHMENTS - total;
                        setCountError(
                            picked.length > room
                                ? `An item can hold ${MAX_ATTACHMENTS} files. ${picked.length - room} weren’t added.`
                                : null,
                        );
                        onFilesChange([...files, ...picked.slice(0, room)]);
                    }}
                />
            )}
            {countError && <FieldError>{countError}</FieldError>}
        </div>
    );
}
