// Builds the TrueType fonts dompdf embeds in PDF exports (D61) from the self-hosted
// @fontsource WOFF files: dompdf only reads TTF. WOFF 1.0 is the same tables, each
// zlib-compressed, so unpacking them back into an sfnt file is lossless.
//
//   node scripts/pdf-fonts.mjs
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { inflateSync } from 'node:zlib';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'resources', 'fonts');

const FONTS = [
    'plus-jakarta-sans/files/plus-jakarta-sans-latin-400-normal',
    'plus-jakarta-sans/files/plus-jakarta-sans-latin-600-normal',
    'plus-jakarta-sans/files/plus-jakarta-sans-latin-ext-400-normal',
    'ibm-plex-mono/files/ibm-plex-mono-latin-400-normal',
    'ibm-plex-mono/files/ibm-plex-mono-latin-500-normal',
    'ibm-plex-mono/files/ibm-plex-mono-latin-ext-400-normal',
];

function woffToTtf(woff) {
    if (woff.readUInt32BE(0) !== 0x774f4646) {
        throw new Error('Not a WOFF 1.0 file');
    }

    const flavor = woff.readUInt32BE(4);
    const numTables = woff.readUInt16BE(12);
    const tables = [];

    for (let i = 0; i < numTables; i++) {
        const at = 44 + i * 20;
        const tag = woff.readUInt32BE(at);
        const offset = woff.readUInt32BE(at + 4);
        const compLength = woff.readUInt32BE(at + 8);
        const origLength = woff.readUInt32BE(at + 12);
        const checksum = woff.readUInt32BE(at + 16);
        const raw = woff.subarray(offset, offset + compLength);
        const data = compLength < origLength ? inflateSync(raw) : raw;

        if (data.length !== origLength) {
            throw new Error('Table length mismatch');
        }

        tables.push({ tag, checksum, data });
    }

    tables.sort((a, b) => a.tag - b.tag);

    let power = 1;
    let exponent = 0;

    while (power * 2 <= numTables) {
        power *= 2;
        exponent++;
    }

    const headerSize = 12 + numTables * 16;
    const pad = (n) => (n + 3) & ~3;
    const size = tables.reduce(
        (sum, t) => sum + pad(t.data.length),
        headerSize,
    );
    const ttf = Buffer.alloc(size);

    ttf.writeUInt32BE(flavor, 0);
    ttf.writeUInt16BE(numTables, 4);
    ttf.writeUInt16BE(power * 16, 6);
    ttf.writeUInt16BE(exponent, 8);
    ttf.writeUInt16BE(numTables * 16 - power * 16, 10);

    let offset = headerSize;

    tables.forEach((t, i) => {
        const at = 12 + i * 16;
        ttf.writeUInt32BE(t.tag, at);
        ttf.writeUInt32BE(t.checksum, at + 4);
        ttf.writeUInt32BE(offset, at + 8);
        ttf.writeUInt32BE(t.data.length, at + 12);
        t.data.copy(ttf, offset);
        offset += pad(t.data.length);
    });

    return ttf;
}

mkdirSync(out, { recursive: true });

for (const font of FONTS) {
    const woff = readFileSync(
        join(root, 'node_modules', '@fontsource', `${font}.woff`),
    );
    const name = font.split('/').pop();
    writeFileSync(join(out, `${name}.ttf`), woffToTtf(woff));
    console.log(`resources/fonts/${name}.ttf`);
}
