<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\GstSupply;

/**
 * GST arithmetic (D159), all in paise. Pure: no database, so every rate and place-of-supply
 * case is unit tested.
 */
final class GstCalculator
{
    /**
     * The place of supply: a foreign party is an import (when we buy) or an export (when we
     * sell); otherwise the same state is intra-state and any other is inter-state. With no
     * party state (an unregistered buyer in our own state), it's intra-state.
     */
    public static function supply(?string $companyState, ?string $partyState, bool $foreign, bool $inward): GstSupply
    {
        if ($foreign) {
            return $inward ? GstSupply::Import : GstSupply::Export;
        }

        return $partyState === null || $partyState === $companyState ? GstSupply::Intra : GstSupply::Inter;
    }

    /**
     * The tax on a taxable value, computed the way an invoice shows it: CGST and SGST each at
     * half the rate, rounded separately; IGST at the full rate; exports zero-rated.
     *
     * @return array{cgst: int, sgst: int, igst: int}
     */
    public static function tax(int $taxable, int $rateBps, GstSupply $supply): array
    {
        return match ($supply) {
            GstSupply::Intra => ['cgst' => self::part($taxable, $rateBps, 2), 'sgst' => self::part($taxable, $rateBps, 2), 'igst' => 0],
            GstSupply::Inter, GstSupply::Import => ['cgst' => 0, 'sgst' => 0, 'igst' => self::part($taxable, $rateBps, 1)],
            GstSupply::Export => ['cgst' => 0, 'sgst' => 0, 'igst' => 0],
        };
    }

    /**
     * An invoice's actual tax split into heads: what the supplier charged is the truth, so
     * intra-state tax is halved with the odd paisa on CGST.
     *
     * @return array{cgst: int, sgst: int, igst: int}
     */
    public static function split(int $gst, GstSupply $supply): array
    {
        return match ($supply) {
            GstSupply::Intra => ['cgst' => $gst - intdiv($gst, 2), 'sgst' => intdiv($gst, 2), 'igst' => 0],
            GstSupply::Inter, GstSupply::Import => ['cgst' => 0, 'sgst' => 0, 'igst' => $gst],
            GstSupply::Export => ['cgst' => 0, 'sgst' => 0, 'igst' => 0],
        };
    }

    /** The taxable value inside a tax-inclusive total. */
    public static function taxableFromTotal(int $total, int $rateBps): int
    {
        return (int) round($total * 10000 / (10000 + $rateBps));
    }

    /** Whether an invoice's tax is the rate's, give or take a rupee (rounding on the bill). */
    public static function plausible(int $taxable, int $gst, int $rateBps, GstSupply $supply): bool
    {
        $expected = array_sum(self::tax($taxable, $rateBps, $supply === GstSupply::Export ? GstSupply::Inter : $supply));

        return abs($expected - $gst) <= 100;
    }

    private static function part(int $taxable, int $rateBps, int $parts): int
    {
        return (int) round($taxable * $rateBps / (10000 * $parts));
    }
}
