import {
    ArrowUpFromLine,
    FileText,
    Paperclip,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ChangeEvent, DragEvent, ReactNode, Ref } from 'react';
import { validateFile, formatFileSize } from '@/components/ui/file-drop.utils';
import type { FileRejection } from '@/components/ui/file-drop.utils';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

/* ----------------------------------------------------------------- file row */

export type FileRowProps = {
    name: string;
    /** Bytes, shown once uploaded ("184 KB"). */
    size?: number;
    /** 'uploading' shows the progress bar and Cancel; 'done' the Uploaded pill and Remove. */
    status: 'uploading' | 'done';
    /** 0–100 while uploading. */
    progress?: number;
    onCancel?: () => void;
    onRemove?: () => void;
    /** Names the remove button: "Remove receipt". */
    removeLabel?: string;
    className?: string;
};

/** An attached file (`cf-file`): uploading with `cf-progress-mini`, or done with Remove. */
export function FileRow({
    name,
    size,
    status,
    progress = 0,
    onCancel,
    onRemove,
    removeLabel = 'Remove file',
    className,
}: FileRowProps) {
    if (status === 'uploading') {
        const pct = Math.max(0, Math.min(100, Math.round(progress)));

        return (
            <div className={cn('cf-file', className)} aria-busy="true">
                <Icon icon={FileText} size={18} />
                <span
                    className="cf-file-name"
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 4,
                    }}
                >
                    <span>
                        {name} · {pct}%
                    </span>
                    <span
                        className="cf-progress-mini"
                        role="progressbar"
                        aria-label={`Uploading ${name}`}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={pct}
                    >
                        <span style={{ width: `${pct}%` }} />
                    </span>
                </span>
                {onCancel && (
                    <IconButton
                        icon={X}
                        size="sm"
                        variant="bare"
                        iconSize={14}
                        aria-label="Cancel upload"
                        onClick={onCancel}
                    />
                )}
            </div>
        );
    }

    return (
        <div className={cn('cf-file', className)}>
            <Icon icon={Paperclip} size={18} />
            <span className="cf-file-name">
                {name}
                {size !== undefined && (
                    <>
                        {' '}
                        <span className="t-muted">
                            · {formatFileSize(size)}
                        </span>
                    </>
                )}
            </span>
            <span className="cf-pill cf-pill--pos cf-pill--sm">Uploaded</span>
            {onRemove && (
                <IconButton
                    icon={X}
                    size="sm"
                    variant="bare"
                    iconSize={14}
                    aria-label={removeLabel}
                    onClick={onRemove}
                />
            )}
        </div>
    );
}

/* ---------------------------------------------------------------- drop zone */

export type FileDropProps = {
    /** Names the file input: "Upload receipt". */
    'aria-label': string;
    /** `zone` (dashed area) or `row` (48 px inline version for forms). */
    variant?: 'zone' | 'row';
    /** Strong first words: "Drop a CSV" (zone) or "Upload" (row). */
    title?: ReactNode;
    /** After the title in the zone: "or browse". */
    hint?: ReactNode;
    /** The limits: "Up to 5 MB" (zone caption) or "PDF, JPG or PNG, up to 10 MB" (row). */
    limits?: ReactNode;
    /** Native accept list, also checked on pick and drop: ".csv" or "application/pdf,image/jpeg,image/png". */
    accept?: string;
    /** Largest file in bytes. */
    maxSize?: number;
    multiple?: boolean;
    disabled?: boolean;
    /** Files that passed the checks. */
    onFiles?: (files: File[]) => void;
    /** Files that didn't (wrong type, too big). The zone shows the first one. */
    onReject?: (rejections: FileRejection[]) => void;
    /** How to fix a rejected file: "Use PDF, JPG or PNG, up to 10 MB." */
    rejectHint?: ReactNode;
    /** An error from outside (upload failed). Shown like a rejection. */
    error?: ReactNode;
    /** Show the dragging state (normally set while a file is over the zone). */
    dragging?: boolean;
    /** Text while dragging: "Release to upload". */
    dragText?: ReactNode;
    /** A file being uploaded or attached: replaces the zone with its row. */
    file?: Omit<
        FileRowProps,
        'onCancel' | 'onRemove' | 'removeLabel' | 'className'
    >;
    onCancel?: () => void;
    onRemove?: () => void;
    removeLabel?: string;
    name?: string;
    className?: string;
    ref?: Ref<HTMLInputElement>;
};

/**
 * Receipts, attachments and CSV imports. A real file input covers the dashed area, so
 * click, keyboard and drag all work; picked files are checked against `accept` and
 * `maxSize` before `onFiles`.
 */
export function FileDrop({
    'aria-label': ariaLabel,
    variant = 'zone',
    title = variant === 'row' ? 'Upload' : 'Drop a file',
    hint = 'or browse',
    limits,
    accept,
    maxSize,
    multiple = false,
    disabled = false,
    onFiles,
    onReject,
    rejectHint,
    error,
    dragging,
    dragText = 'Release to upload',
    file,
    onCancel,
    onRemove,
    removeLabel,
    name,
    className,
    ref,
}: FileDropProps) {
    const [over, setOver] = useState(false);
    const [rejected, setRejected] = useState<string | null>(null);

    if (file) {
        return (
            <FileRow
                {...file}
                onCancel={onCancel}
                onRemove={onRemove}
                removeLabel={removeLabel}
                className={className}
            />
        );
    }

    const isDrag = dragging ?? over;
    const errorTitle = error ?? rejected;
    const row = variant === 'row';
    const iconSize = row ? 18 : 22;

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const picked = Array.from(input.files ?? []);
        const files = multiple ? picked : picked.slice(0, 1);
        const accepted: File[] = [];
        const rejections: FileRejection[] = [];

        for (const item of files) {
            const problem = validateFile(item, { accept, maxSize });

            if (problem) {
                rejections.push({ file: item, ...problem });
            } else {
                accepted.push(item);
            }
        }

        setOver(false);
        setRejected(rejections[0]?.title ?? null);

        if (rejections.length > 0) {
            onReject?.(rejections);
        }

        if (accepted.length > 0) {
            onFiles?.(accepted);
        }

        // Let the same file be picked again after fixing something.
        input.value = '';
    };

    const leave = (event: DragEvent<HTMLLabelElement>) => {
        const next = event.relatedTarget;

        if (!(next instanceof Node) || !event.currentTarget.contains(next)) {
            setOver(false);
        }
    };

    const input = (
        <input
            ref={ref}
            type="file"
            name={name}
            aria-label={ariaLabel}
            accept={accept}
            multiple={multiple}
            disabled={disabled}
            onChange={handleChange}
        />
    );

    let content: ReactNode;

    if (isDrag) {
        content = (
            <>
                <Icon icon={ArrowUpFromLine} size={iconSize} />
                <span className="font-medium">{dragText}</span>
            </>
        );
    } else if (errorTitle) {
        content = (
            <>
                <Icon icon={TriangleAlert} size={iconSize} />
                <span className="font-medium">{errorTitle}</span>
                {rejectHint !== undefined && (
                    <span className="t-caption">{rejectHint}</span>
                )}
            </>
        );
    } else if (row) {
        content = (
            <>
                <Icon
                    icon={ArrowUpFromLine}
                    size={iconSize}
                    className="text-ink"
                />
                <span>
                    <span className="font-medium text-ink">{title}</span>{' '}
                    {limits}
                </span>
            </>
        );
    } else {
        content = (
            <>
                <Icon
                    icon={ArrowUpFromLine}
                    size={iconSize}
                    className="text-ink"
                />
                <span>
                    <span className="font-medium text-ink">{title}</span> {hint}
                </span>
                {limits !== undefined && (
                    <span className="t-caption">{limits}</span>
                )}
            </>
        );
    }

    return (
        <label
            className={cn(
                'cf-drop',
                row && 'cf-drop--row',
                isDrag && 'is-drag',
                !isDrag && errorTitle && 'is-error',
                disabled && 'is-disabled',
                className,
            )}
            role={!isDrag && errorTitle ? 'alert' : undefined}
            onDragEnter={() => !disabled && setOver(true)}
            onDragOver={() => !disabled && !over && setOver(true)}
            onDragLeave={leave}
            onDrop={() => setOver(false)}
        >
            {content}
            {input}
        </label>
    );
}
