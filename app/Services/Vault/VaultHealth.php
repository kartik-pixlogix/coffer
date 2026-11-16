<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultItemType;
use App\Models\VaultItem;
use App\Models\VaultSecret;
use Illuminate\Support\Collection;

/**
 * Security health (spec §14.4) over a set of items: counts per problem and the score
 * (100, minus per item and per category up to a cap, floor 0). Part A shows it on the
 * rail card and the dashboard; part B builds the Health page, HIBP checks and fix-it
 * lists on the same counts. Reuse is found by equal HMAC fingerprints, never plaintext.
 */
final class VaultHealth
{
    /** Points per item and the most a category can take. The caps aren't in the spec. [VERIFY] (D70) */
    public const array WEIGHTS = [
        'weak' => [4, 20],
        'reused' => [5, 20],
        'old' => [2, 10],
        'breached' => [10, 30],
        'expiring' => [2, 10],
        'no2fa' => [1, 10],
    ];

    public const int OLD_DAYS = 180;

    public const int EXPIRING_DAYS = 30;

    /**
     * @param  Collection<int, VaultItem>|\Illuminate\Database\Eloquent\Collection<int, VaultItem>  $items
     * @return array{score: int|null, grade: string|null, counts: array<string, int>, reused: array<int, list<int>>, ids: array<string, list<int>>}
     */
    public function summarise(Collection $items): array
    {
        $reused = $this->reusedGroups($items);
        $ids = array_fill_keys(array_keys(self::WEIGHTS), []);
        $today = now()->startOfDay();

        foreach ($items as $item) {
            if ($item->strength !== null && $item->strength <= 1) {
                $ids['weak'][] = $item->id;
            }

            if (isset($reused[$item->id])) {
                $ids['reused'][] = $item->id;
            }

            if ($item->strength !== null && $item->rotated_at !== null && $item->rotated_at->lt(now()->subDays(self::OLD_DAYS))) {
                $ids['old'][] = $item->id;
            }

            if ($item->breached) {
                $ids['breached'][] = $item->id;
            }

            if ($item->expires_at !== null && $item->expires_at->lte($today->addDays(self::EXPIRING_DAYS))) {
                $ids['expiring'][] = $item->id;
            }

            if ($item->type === VaultItemType::Login && ! $item->has_totp) {
                $ids['no2fa'][] = $item->id;
            }
        }

        $score = 100;

        foreach (self::WEIGHTS as $key => [$each, $cap]) {
            $score -= min($cap, $each * count($ids[$key]));
        }

        $score = max(0, $score);
        $empty = $items->isEmpty();

        return [
            'score' => $empty ? null : $score,
            'grade' => $empty ? null : self::grade($score),
            'counts' => array_map('count', $ids),
            'reused' => $reused,
            'ids' => $ids,
        ];
    }

    /** "Excellent", "Good", "Fair", "Needs work" (VaultHealth.dc.html). */
    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 80 => 'good',
            $score >= 60 => 'fair',
            default => 'needs work',
        };
    }

    /**
     * Items whose password equals another item's (same fingerprint).
     *
     * @param  Collection<int, VaultItem>|\Illuminate\Database\Eloquent\Collection<int, VaultItem>  $items
     * @return array<int, list<int>> item id → the other items with the same password
     */
    public function reusedGroups(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $rows = VaultSecret::query()
            ->whereIn('vault_item_id', $items->pluck('id'))
            ->whereNotNull('fingerprint')
            ->get(['vault_item_id', 'fingerprint']);

        $byPrint = [];

        foreach ($rows as $row) {
            $byPrint[(string) $row->fingerprint][] = $row->vault_item_id;
        }

        $out = [];

        foreach ($byPrint as $ids) {
            $ids = array_values(array_unique($ids));

            if (count($ids) < 2) {
                continue;
            }

            foreach ($ids as $id) {
                $out[$id] = array_values(array_diff($ids, [$id]));
            }
        }

        return $out;
    }
}
