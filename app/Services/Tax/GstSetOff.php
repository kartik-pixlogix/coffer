<?php

declare(strict_types=1);

namespace App\Services\Tax;

/**
 * GST set-off (D163): input tax credit paid against output tax, in the order the law sets
 * (CGST Act s.49, Rule 88A), the rest paid in cash. [VERIFY] with the CA.
 *
 * - IGST credit goes first: to IGST, then CGST, then SGST.
 * - CGST credit: to CGST, then IGST. Never to SGST.
 * - SGST credit: to SGST, then IGST. Never to CGST.
 * - Reverse-charge GST can't be paid with credit: always cash.
 *
 * Pure arithmetic in paise, so every branch is unit tested.
 */
final class GstSetOff
{
    public const array HEADS = ['igst', 'cgst', 'sgst'];

    /**
     * @param  array<string, int>  $output  output tax owed per head
     * @param  array<string, int>  $rcm  reverse-charge GST owed per head
     * @param  array<string, int>  $credit  input tax credit available per head
     * @return array{used: array<string, array<string, int>>, usedByCredit: array<string, int>, cash: array<string, int>, creditLeft: array<string, int>, cashTotal: int}
     */
    public static function settle(array $output, array $rcm, array $credit): array
    {
        $owed = [];
        $left = [];
        $used = [];

        foreach (self::HEADS as $head) {
            $owed[$head] = max(0, $output[$head] ?? 0);
            $left[$head] = max(0, $credit[$head] ?? 0);
            $used[$head] = array_fill_keys(self::HEADS, 0);
        }

        $apply = function (string $from, string $to) use (&$owed, &$left, &$used): void {
            $amount = min($owed[$to], $left[$from]);
            $owed[$to] -= $amount;
            $left[$from] -= $amount;
            $used[$from][$to] += $amount;
        };

        // IGST credit first, in full where it can go.
        $apply('igst', 'igst');
        $apply('igst', 'cgst');
        $apply('igst', 'sgst');
        // Then CGST and SGST credit, each on its own head, then IGST.
        $apply('cgst', 'cgst');
        $apply('cgst', 'igst');
        $apply('sgst', 'sgst');
        $apply('sgst', 'igst');

        $cash = [];

        foreach (self::HEADS as $head) {
            $cash[$head] = $owed[$head] + max(0, $rcm[$head] ?? 0);
        }

        return [
            'used' => $used,
            'usedByCredit' => array_map(fn (array $to) => array_sum($to), $used),
            'cash' => $cash,
            'creditLeft' => $left,
            'cashTotal' => array_sum($cash),
        ];
    }
}
