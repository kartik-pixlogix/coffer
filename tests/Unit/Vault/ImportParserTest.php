<?php

declare(strict_types=1);

use App\Enums\VaultImportFormat;
use App\Enums\VaultItemType;
use App\Services\Vault\Transfer\ExportCrypto;
use App\Services\Vault\Transfer\ImportParser;
use App\Services\Vault\Transfer\ImportRow;

function importFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/vault-import/{$name}"));
}

/** @return array{rows: list<ImportRow>, skipped: list<array{line: int, reason: string}>} */
function parseFixture(string $name, array $mapping = []): array
{
    $bytes = importFixture($name);

    return (new ImportParser)->parse(ImportParser::detect($bytes), $bytes, $mapping);
}

test('formats are detected from the file', function (string $file, VaultImportFormat $format) {
    expect(ImportParser::detect(importFixture($file)))->toBe($format);
})->with([
    ['bitwarden.json', VaultImportFormat::BitwardenJson],
    ['bitwarden.csv', VaultImportFormat::BitwardenCsv],
    ['1password.csv', VaultImportFormat::OnePassword],
    ['lastpass.csv', VaultImportFormat::LastPass],
    ['chrome.csv', VaultImportFormat::Chrome],
    ['generic.csv', VaultImportFormat::Csv],
]);

test('Bitwarden JSON: logins with URL, 2FA key, custom fields and folder; notes; cards and bad keys reported', function () {
    ['rows' => $rows, 'skipped' => $skipped] = parseFixture('bitwarden.json');

    expect($rows)->toHaveCount(3);
    [$github, $note, $bad] = $rows;

    expect($github->type)->toBe(VaultItemType::Login)
        ->and($github->name)->toBe('GitHub')
        ->and($github->provider)->toBe('github')
        ->and($github->fields)->toBe(['url' => 'https://github.com/login', 'username' => 'vertex-admin'])
        ->and($github->secrets)->toBe(['password' => 'fake-Bw-Pass-1!', 'notes' => 'fake-note-github', 'totp' => 'JBSWY3DPEHPK3PXP'])
        ->and($github->customFields)->toBe([
            ['label' => 'Recovery email', 'secret' => false, 'value' => 'ops@example.test'],
            ['label' => 'PAT', 'secret' => true, 'value' => 'fake-pat-123'],
        ])
        ->and($github->tags)->toBe(['saarthi']);

    expect($note->type)->toBe(VaultItemType::Note)->and($note->secrets)->toBe(['note' => 'fake-wifi-note'])
        ->and($bad->secrets)->not->toHaveKey('totp')->and($bad->notes)->toBe(['2FA key skipped (not a valid setup key)'])
        ->and($skipped)->toBe([['line' => 3, 'reason' => 'Cards aren’t imported']]);
});

test('an encrypted Bitwarden export is refused with what to do', function () {
    expect(fn () => (new ImportParser)->parse(VaultImportFormat::BitwardenJson, '{"encrypted": true, "items": []}'))
        ->toThrow(RuntimeException::class, 'This Bitwarden export is encrypted.');
});

test('Bitwarden CSV: multi-line notes, "name: value" fields, notes and skipped cards', function () {
    ['rows' => $rows, 'skipped' => $skipped] = parseFixture('bitwarden.csv');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->name)->toBe('Netlify')
        ->and($rows[0]->secrets)->toBe(['password' => 'fake-Bw-Csv-1!', 'notes' => "fake multi\nline note"])
        ->and($rows[0]->customFields)->toBe([['label' => 'Site ID', 'secret' => false, 'value' => 'abc123']])
        ->and($rows[0]->tags)->toBe(['saarthi'])
        ->and($rows[1]->type)->toBe(VaultItemType::Note)
        ->and($skipped)->toBe([['line' => 4, 'reason' => 'Bitwarden card items aren’t imported']]);
});

test('1Password CSV: the otpauth URI becomes the setup key; tags split; empty rows reported', function () {
    ['rows' => $rows, 'skipped' => $skipped] = parseFixture('1password.csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Vercel')
        ->and($rows[0]->secrets)->toBe(['password' => 'fake-1P-Pass-1!', 'notes' => 'fake-1p-note', 'totp' => 'JBSWY3DPEHPK3PXP'])
        ->and($rows[0]->tags)->toBe(['deploy', 'internal'])
        ->and($skipped)->toBe([['line' => 3, 'reason' => 'Nothing to import']]);
});

test('LastPass CSV: passwords keep their spaces, grouping becomes tags, http://sn is a secure note', function () {
    ['rows' => $rows] = parseFixture('lastpass.csv');

    expect($rows[0]->secrets['password'])->toBe(' fake-LP-Pass-1! ')
        ->and($rows[0]->provider)->toBe('hostinger')
        ->and($rows[0]->tags)->toBe(['clients', 'asha'])
        ->and($rows[1]->type)->toBe(VaultItemType::Note)
        ->and($rows[1]->name)->toBe('Router admin')
        ->and($rows[1]->secrets)->toBe(['note' => 'fake-lp-secure-note']);
});

test('Chrome CSV: a missing name falls back to the site', function () {
    ['rows' => $rows] = parseFixture('chrome.csv');

    expect($rows[0]->name)->toBe('dash.cloudflare.com')
        ->and($rows[1]->name)->toBe('example.test')
        ->and($rows[1]->secrets)->toBe(['password' => 'fake-Chrome-Pass-2!', 'notes' => 'fake chrome note']);
});

test('a plain CSV is mapped by guessed columns (semicolons too)', function () {
    $bytes = importFixture('generic.csv');
    $header = ['Site', 'Login', 'Secret', 'Comments', 'Folder'];
    $mapping = ImportParser::guessMapping($header);

    expect($mapping)->toBe([0 => 'name', 1 => 'username', 2 => 'password', 3 => 'notes', 4 => 'tags']);

    ['rows' => $rows] = (new ImportParser)->parse(VaultImportFormat::Csv, $bytes, $mapping);
    expect($rows[0]->name)->toBe('Razorpay dashboard')
        ->and($rows[0]->fields)->toBe(['username' => 'finance@example.test'])
        ->and($rows[0]->secrets)->toBe(['password' => 'fake-Generic-Pass-1!', 'notes' => 'fake generic note'])
        ->and($rows[0]->tags)->toBe(['payments'])
        ->and($rows[1]->type)->toBe(VaultItemType::Note);
});

test('import rows never serialise and dump without values', function () {
    ['rows' => $rows] = parseFixture('chrome.csv');

    expect(fn () => serialize($rows[0]))->toThrow(LogicException::class)
        ->and(print_r($rows[0], true))->not->toContain('fake-Chrome-Pass-1!');
});

test('the export envelope round-trips with Argon2id and with PBKDF2, and refuses a wrong passphrase or a change', function (bool $argon) {
    $crypto = new ExportCrypto;
    $file = $crypto->seal('{"items":[]}', 'correct horse battery staple', ['company' => 'Saarthi Portal JV', 'items' => 0], $argon);
    $data = json_decode($file, true);

    expect($data['format'])->toBe('coffer-vault-export')
        ->and($data['kdf']['name'])->toBe($argon ? 'argon2id' : 'pbkdf2-sha256')
        ->and($data['cipher']['name'])->toBe('aes-256-gcm')
        ->and($file)->not->toContain('items":[]')
        ->and(ExportCrypto::looksLikeExport($file))->toBeTrue()
        ->and($crypto->open($file, 'correct horse battery staple'))->toBe('{"items":[]}');

    expect(fn () => $crypto->open($file, 'wrong horse battery staple'))->toThrow(RuntimeException::class, 'That passphrase doesn’t open this file');

    $data['company'] = 'Someone else';
    expect(fn () => $crypto->open((string) json_encode($data), 'correct horse battery staple'))->toThrow(RuntimeException::class, 'That passphrase doesn’t open this file');
})->with(['argon2id' => true, 'pbkdf2' => false]);
