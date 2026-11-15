import { useEffect, useState } from 'react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { FileDrop, FileRow } from '@/components/ui/file-drop';
import type { FileDropProps } from '@/components/ui/file-drop';

const MB = 1024 * 1024;

type Upload = { name: string; size: number; progress: number };

/** Picks a file, fakes the upload progress, then shows it attached. */
function LiveDrop(props: Omit<FileDropProps, 'file' | 'onFiles'>) {
    const [upload, setUpload] = useState<Upload | null>(null);

    useEffect(() => {
        if (!upload || upload.progress >= 100) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                setUpload({
                    ...upload,
                    progress: Math.min(100, upload.progress + 17),
                }),
            250,
        );

        return () => window.clearTimeout(timer);
    }, [upload]);

    return (
        <FileDrop
            {...props}
            file={
                upload
                    ? {
                          name: upload.name,
                          size: upload.size,
                          progress: upload.progress,
                          status: upload.progress >= 100 ? 'done' : 'uploading',
                      }
                    : undefined
            }
            onFiles={([file]) =>
                setUpload({ name: file.name, size: file.size, progress: 0 })
            }
            onCancel={() => setUpload(null)}
            onRemove={() => setUpload(null)}
        />
    );
}

const RECEIPT_TYPES = '.pdf,.jpg,.jpeg,.png';
const RECEIPT_HINT = 'Use PDF, JPG or PNG, up to 10 MB.';

export default {
    name: 'FileDrop',
    group: 'Forms',
    order: 11,
    subtitle:
        'Receipts and CSV imports · idle, hover, dragging, uploading, done, error',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <LiveDrop
                aria-label="Upload timesheet CSV"
                title="Drop a CSV"
                hint="or browse"
                limits="Up to 5 MB"
                accept=".csv"
                maxSize={5 * MB}
                rejectHint="Use a CSV file, up to 5 MB."
            />
            <FileDrop
                aria-label="Upload file"
                dragging
                dragText="Release to upload hours-oct-2026.csv"
            />
            <FileRow
                name="macbook-air-invoice.pdf"
                status="uploading"
                progress={62}
                onCancel={() => {}}
            />
            <FileRow
                name="macbook-air-invoice.pdf"
                size={188_416}
                status="done"
                removeLabel="Remove receipt"
                onRemove={() => {}}
            />
            <FileDrop
                aria-label="Upload receipt"
                error="receipt.heic isn’t supported"
                rejectHint={RECEIPT_HINT}
                accept={RECEIPT_TYPES}
            />
            <LiveDrop
                aria-label="Upload receipt"
                variant="row"
                title="Upload"
                limits="PDF, JPG or PNG, up to 10 MB"
                accept={RECEIPT_TYPES}
                maxSize={10 * MB}
                rejectHint={RECEIPT_HINT}
                removeLabel="Remove receipt"
            />
        </PvGrid>
    ),
} satisfies GallerySection;
