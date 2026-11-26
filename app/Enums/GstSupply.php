<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a supply happens, which decides the tax heads (D159): within the company's state
 * (CGST + SGST), to or from another state (IGST), imported services from abroad (IGST under
 * reverse charge) or exported (zero-rated under a letter of undertaking).
 */
enum GstSupply: string
{
    case Intra = 'intra';
    case Inter = 'inter';
    case Import = 'import';
    case Export = 'export';

    public function label(): string
    {
        return match ($this) {
            self::Intra => 'Within the state (CGST + SGST)',
            self::Inter => 'Another state (IGST)',
            self::Import => 'Imported from abroad (IGST, reverse charge)',
            self::Export => 'Export (zero-rated)',
        };
    }
}
