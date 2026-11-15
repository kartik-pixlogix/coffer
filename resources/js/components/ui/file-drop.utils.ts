import { formatIndianNumber } from '@/lib/format';

/** "184 KB", "5 MB", "1.2 MB", "900 B" (binary units, as file managers show them). */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${formatIndianNumber(Math.round(bytes / 1024))} KB`;
    }

    const mb = Math.round((bytes / (1024 * 1024)) * 10) / 10;

    return `${String(mb).replace(/\.0$/, '')} MB`;
}

/** Does a file match an `accept` list like ".csv", "application/pdf,image/*"? Empty accept allows all. */
export function matchesAccept(
    file: { name: string; type: string },
    accept: string | undefined,
): boolean {
    const rules = (accept ?? '')
        .split(',')
        .map((rule) => rule.trim().toLowerCase())
        .filter(Boolean);

    if (rules.length === 0) {
        return true;
    }

    const name = file.name.toLowerCase();
    const type = file.type.toLowerCase();

    return rules.some((rule) => {
        if (rule.startsWith('.')) {
            return name.endsWith(rule);
        }

        if (rule.endsWith('/*')) {
            return type.startsWith(rule.slice(0, -1));
        }

        return type === rule;
    });
}

export type FileRejection = {
    file: File;
    reason: 'type' | 'size' | 'count';
    /** "receipt.heic isn’t supported" */
    title: string;
};

/**
 * Check a file against `accept` and `maxSize` (bytes). Returns why it can't be used,
 * or null when it's fine.
 */
export function validateFile(
    file: { name: string; type: string; size: number },
    { accept, maxSize }: { accept?: string; maxSize?: number },
): { reason: 'type' | 'size'; title: string } | null {
    if (!matchesAccept(file, accept)) {
        return { reason: 'type', title: `${file.name} isn’t supported` };
    }

    if (maxSize !== undefined && file.size > maxSize) {
        return {
            reason: 'size',
            title: `${file.name} is over ${formatFileSize(maxSize)}`,
        };
    }

    return null;
}
