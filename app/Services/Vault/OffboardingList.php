<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultAccessLevel;
use App\Models\User;
use App\Models\VaultOffboarding;

/**
 * The open offboarding lists (spec §14.2, D73) as Settings › Members and the health page
 * show them: each person, why, and the items they could use with Rotate. The viewer only
 * sees names of items they can see themselves; the rest are counted.
 */
final readonly class OffboardingList
{
    public function __construct(private VaultAccess $access) {}

    /** @return list<array{id: int, name: string, reason: string, at: string, total: int, open: int, hidden: int, items: list<array{id: int, name: string, monogram: string, vault: string, rotated: bool, canRotate: bool}>}> */
    public function forViewer(User $viewer): array
    {
        $out = [];

        foreach (VaultOffboarding::query()->open()->latest('id')->limit(20)->get() as $offboarding) {
            $items = $offboarding->items();
            $rows = [];
            $hidden = 0;
            $open = 0;

            foreach ($items as $item) {
                $rotated = $offboarding->isRotated($item);
                $open += $rotated ? 0 : 1;
                $level = $viewer->hasTwoFactorEnabled() ? $this->access->itemLevel($viewer, $item) : null;

                if ($level === null) {
                    $hidden++;

                    continue;
                }

                $rows[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'monogram' => $item->monogram(),
                    'vault' => $item->vault->name,
                    'rotated' => $rotated,
                    'canRotate' => $level->atLeast(VaultAccessLevel::Edit),
                ];
            }

            $out[] = [
                'id' => $offboarding->id,
                'name' => $offboarding->user_name,
                'reason' => $offboarding->reasonText(),
                'at' => $offboarding->created_at->toIso8601String(),
                'total' => $items->count(),
                'open' => $open,
                'hidden' => $hidden,
                'items' => $rows,
            ];
        }

        return $out;
    }

    /**
     * Items still to rotate across open lists (for the health page's fix-it list).
     *
     * @return list<int>
     */
    public function openItemIds(): array
    {
        $ids = [];

        foreach (VaultOffboarding::query()->open()->get() as $offboarding) {
            foreach ($offboarding->items() as $item) {
                if (! $offboarding->isRotated($item)) {
                    $ids[$item->id] = $item->id;
                }
            }
        }

        return array_values($ids);
    }
}
