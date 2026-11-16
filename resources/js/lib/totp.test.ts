import { describe, expect, it } from 'vite-plus/test';
import {
    base32Decode,
    normaliseTotpKey,
    totpCode,
    totpCounter,
    totpKeyState,
} from './totp';

// RFC 6238 appendix B: the SHA-1 seed "12345678901234567890" in base32.
const RFC_KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

describe('normaliseTotpKey (matches Totp.php::normalise)', () => {
    it('accepts spaces, lower case, dashes and padding', () => {
        expect(normaliseTotpKey('JBSW Y3DP EHPK 3PXP')).toBe(
            'JBSWY3DPEHPK3PXP',
        );
        expect(normaliseTotpKey('jbsw-y3dp-ehpk-3pxp')).toBe(
            'JBSWY3DPEHPK3PXP',
        );
        expect(normaliseTotpKey('JBSWY3DPEHPK3PXP====')).toBe(
            'JBSWY3DPEHPK3PXP',
        );
    });

    it('reads the secret from an otpauth:// link', () => {
        expect(
            normaliseTotpKey(
                'otpauth://totp/GitLab:deploy@saarthi-jv.in?secret=JBSWY3DPEHPK3PXP&issuer=GitLab',
            ),
        ).toBe('JBSWY3DPEHPK3PXP');
        expect(normaliseTotpKey('otpauth://totp/x?issuer=GitLab')).toBeNull();
    });

    it('rejects other characters and keys that are too short', () => {
        expect(normaliseTotpKey('')).toBeNull();
        expect(normaliseTotpKey('JBSW0Y3DP1EHPK')).toBeNull();
        expect(normaliseTotpKey('JBSWY3DP')).toBeNull();
    });
});

describe('totpKeyState', () => {
    it('tells empty, invalid, partial and whole keys apart', () => {
        expect(totpKeyState('  ')).toBe('empty');
        expect(totpKeyState('JBSW 8')).toBe('invalid');
        expect(totpKeyState('JBSW Y3')).toBe('short');
        expect(totpKeyState('JBSW Y3DP EHPK 3PXP')).toBe('ok');
    });
});

describe('base32Decode', () => {
    it('decodes RFC 4648 base32', () => {
        expect(new TextDecoder().decode(base32Decode(RFC_KEY))).toBe(
            '12345678901234567890',
        );
        expect(new TextDecoder().decode(base32Decode('MZXW6YTBOI'))).toBe(
            'foobar',
        );
    });
});

describe('totpCode (RFC 6238, SHA-1, 6 digits)', () => {
    it('matches the RFC test vectors', async () => {
        expect(await totpCode(RFC_KEY, 59_000)).toBe('287082');
        expect(await totpCode(RFC_KEY, 1_111_111_109_000)).toBe('081804');
        expect(await totpCode(RFC_KEY, 1_234_567_890_000)).toBe('005924');
        expect(await totpCode(RFC_KEY, 2_000_000_000_000)).toBe('279037');
        expect(await totpCode(RFC_KEY, 20_000_000_000_000)).toBe('353130');
    });

    it('works on a pasted, spaced key and returns null for a bad one', async () => {
        const spaced = RFC_KEY.toLowerCase().replace(/(.{4})/g, '$1 ');
        expect(await totpCode(spaced, 59_000)).toBe('287082');
        expect(await totpCode('not a key', 59_000)).toBeNull();
    });

    it('counts 30-second steps from the epoch', () => {
        expect(totpCounter(59_000)).toBe(1);
        expect(totpCounter(60_000)).toBe(2);
    });
});
