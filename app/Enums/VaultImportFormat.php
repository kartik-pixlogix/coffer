<?php

declare(strict_types=1);

namespace App\Enums;

/** Import file formats (spec §14.4 "Import and export", D77), detected from the file. */
enum VaultImportFormat: string
{
    case Coffer = 'coffer';
    case BitwardenJson = 'bitwarden_json';
    case BitwardenCsv = 'bitwarden_csv';
    case OnePassword = '1password_csv';
    case LastPass = 'lastpass_csv';
    case Chrome = 'chrome_csv';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Coffer => 'Coffer encrypted export',
            self::BitwardenJson => 'Bitwarden (JSON)',
            self::BitwardenCsv => 'Bitwarden (CSV)',
            self::OnePassword => '1Password (CSV)',
            self::LastPass => 'LastPass (CSV)',
            self::Chrome => 'Chrome passwords (CSV)',
            self::Csv => 'CSV',
        };
    }

    /** Only a plain CSV needs its columns mapped. */
    public function needsMapping(): bool
    {
        return $this === self::Csv;
    }
}
