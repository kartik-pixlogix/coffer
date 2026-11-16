<?php

declare(strict_types=1);

namespace App\Services\Vault;

/** A ciphertext (base64 of nonce ‖ tag ‖ data) and the company data-key version that sealed it. */
final readonly class Sealed
{
    public function __construct(
        public string $ciphertext,
        public int $keyVersion,
    ) {}
}
