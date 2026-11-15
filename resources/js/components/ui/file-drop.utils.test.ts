import { describe, expect, it } from 'vite-plus/test';
import { formatFileSize, matchesAccept, validateFile } from './file-drop.utils';

describe('formatFileSize', () => {
    it.each([
        [900, '900 B'],
        [188_416, '184 KB'],
        [5 * 1024 * 1024, '5 MB'],
        [1.25 * 1024 * 1024, '1.3 MB'],
    ])('%i → %s', (bytes, text) => {
        expect(formatFileSize(bytes)).toBe(text);
    });
});

describe('matchesAccept', () => {
    const pdf = { name: 'Invoice.PDF', type: 'application/pdf' };
    const heic = { name: 'receipt.heic', type: 'image/heic' };

    it('allows anything without an accept list', () => {
        expect(matchesAccept(heic, undefined)).toBe(true);
        expect(matchesAccept(heic, '')).toBe(true);
    });

    it('matches extensions case-insensitively', () => {
        expect(matchesAccept(pdf, '.pdf,.jpg')).toBe(true);
        expect(matchesAccept(heic, '.pdf, .jpg, .png')).toBe(false);
    });

    it('matches exact and wildcard MIME types', () => {
        expect(matchesAccept(pdf, 'application/pdf')).toBe(true);
        expect(matchesAccept(heic, 'image/*')).toBe(true);
        expect(matchesAccept(pdf, 'image/*')).toBe(false);
    });
});

describe('validateFile', () => {
    const rules = { accept: '.pdf,.jpg,.png', maxSize: 10 * 1024 * 1024 };

    it('accepts a valid file', () => {
        expect(
            validateFile({ name: 'a.pdf', type: '', size: 1000 }, rules),
        ).toBeNull();
    });

    it('names an unsupported type', () => {
        expect(
            validateFile(
                { name: 'receipt.heic', type: 'image/heic', size: 1 },
                rules,
            ),
        ).toEqual({ reason: 'type', title: 'receipt.heic isn’t supported' });
    });

    it('names a file that is too big', () => {
        expect(
            validateFile(
                {
                    name: 'scan.pdf',
                    type: 'application/pdf',
                    size: 11 * 1024 * 1024,
                },
                rules,
            ),
        ).toEqual({ reason: 'size', title: 'scan.pdf is over 10 MB' });
    });
});
