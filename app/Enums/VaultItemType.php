<?php

declare(strict_types=1);

namespace App\Enums;

/** Spec §14.1 item types. Their fields are in App\Services\Vault\ItemTemplates. */
enum VaultItemType: string
{
    case Login = 'login';
    case ApiKey = 'api_key';
    case Server = 'server';
    case Database = 'database';
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Email = 'email';
    case Payment = 'payment';
    case Licence = 'licence';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Website login',
            self::ApiKey => 'API key / token',
            self::Server => 'Server / SSH',
            self::Database => 'Database',
            self::Hosting => 'Hosting / cPanel / FTP',
            self::Domain => 'Domain & DNS',
            self::Email => 'Email account',
            self::Payment => 'Payment gateway',
            self::Licence => 'Software licence',
            self::Note => 'Secure note',
        };
    }

    /** The list's strength column for items without a scored password ("API token", "Key file"…). */
    public function badge(): string
    {
        return match ($this) {
            self::Login => 'Login',
            self::ApiKey => 'API token',
            self::Server => 'Key file',
            self::Database => 'Database',
            self::Hosting => 'Hosting',
            self::Domain => 'Domain',
            self::Email => 'Email',
            self::Payment => 'API keys',
            self::Licence => 'Licence',
            self::Note => 'Note',
        };
    }
}
