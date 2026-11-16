/*
 * Live preview of an item's one-time code while its 2FA setup key is typed (spec §14.3,
 * VaultAddLogin.dc.html). RFC 6238 TOTP (HMAC-SHA-1, 30 s, 6 digits) via WebCrypto.
 * Mirrors app/Services/Vault/Totp.php::normalise so the form accepts what the server does.
 * The key lives only in component state: never log or store it.
 */

export const TOTP_PERIOD = 30;
export const TOTP_DIGITS = 6;

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Where a typed setup key stands. `short` = valid letters but not a whole key yet. */
export type TotpKeyState = 'empty' | 'invalid' | 'short' | 'ok';

/** Pull the secret out of an otpauth:// URI, or return the input as typed. */
function secretFromInput(input: string): string {
    const value = input.trim();

    if (!value.toLowerCase().startsWith('otpauth://')) {
        return value;
    }

    const query = value.split('?')[1] ?? '';

    for (const pair of query.split('&')) {
        const [name, raw = ''] = pair.split('=');

        if (name.toLowerCase() === 'secret') {
            try {
                return decodeURIComponent(raw.replace(/\+/g, ' '));
            } catch {
                return '';
            }
        }
    }

    return '';
}

/** Spaces, dashes and "=" padding removed, upper-cased; not yet length-checked. */
function compact(input: string): string {
    return secretFromInput(input)
        .replace(/[\s-]+/g, '')
        .toUpperCase()
        .replace(/=+$/, '');
}

/**
 * "JBSW Y3DP EHPK 3PXP", lower case or an otpauth:// link → "JBSWY3DPEHPK3PXP";
 * null when it isn't a base32 key of 10–128 characters (same rule as the server).
 */
export function normaliseTotpKey(input: string): string | null {
    if (input.trim() === '') {
        return null;
    }

    const key = compact(input);

    return /^[A-Z2-7]{10,128}$/.test(key) ? key : null;
}

export function totpKeyState(input: string): TotpKeyState {
    if (input.trim() === '') {
        return 'empty';
    }

    const key = compact(input);

    if (!/^[A-Z2-7]*$/.test(key) || key.length > 128) {
        return 'invalid';
    }

    return key.length >= 10 ? 'ok' : 'short';
}

/** RFC 4648 base32 → bytes (expects a normalised key; trailing bits are dropped). */
export function base32Decode(key: string): Uint8Array {
    const out: number[] = [];
    let bits = 0;
    let value = 0;

    for (const char of key) {
        const index = BASE32.indexOf(char);

        if (index < 0) {
            throw new Error('Not a base32 key.');
        }

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            out.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return new Uint8Array(out);
}

/** The RFC 6238 time step for a moment (ms since the epoch). */
export function totpCounter(nowMs: number, period = TOTP_PERIOD): number {
    return Math.floor(nowMs / 1000 / period);
}

/** HOTP (RFC 4226) for raw key bytes and a counter. */
export async function hotp(
    keyBytes: Uint8Array,
    counter: number,
    digits = TOTP_DIGITS,
): Promise<string> {
    const message = new ArrayBuffer(8);
    const view = new DataView(message);
    view.setUint32(0, Math.floor(counter / 2 ** 32));
    view.setUint32(4, counter >>> 0);

    const raw = new Uint8Array(keyBytes.byteLength);
    raw.set(keyBytes);

    const key = await crypto.subtle.importKey(
        'raw',
        raw,
        { name: 'HMAC', hash: 'SHA-1' },
        false,
        ['sign'],
    );
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, message));
    const offset = mac[mac.length - 1] & 0x0f;
    const binary =
        ((mac[offset] & 0x7f) << 24) |
        (mac[offset + 1] << 16) |
        (mac[offset + 2] << 8) |
        mac[offset + 3];

    return String(binary % 10 ** digits).padStart(digits, '0');
}

/** The current code for a typed setup key, or null when the key isn't valid. */
export async function totpCode(
    input: string,
    nowMs: number = Date.now(),
    period = TOTP_PERIOD,
    digits = TOTP_DIGITS,
): Promise<string | null> {
    const key = normaliseTotpKey(input);

    if (key === null) {
        return null;
    }

    return hotp(base32Decode(key), totpCounter(nowMs, period), digits);
}
