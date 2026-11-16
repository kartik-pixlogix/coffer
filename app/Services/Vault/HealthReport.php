<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultAccessLevel;
use App\Models\User;
use App\Models\VaultItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Security health page (VaultHealth.dc.html, spec §14.4, D74): the score and counts
 * from VaultHealth, plus a fix-it list per category with what's wrong and the action
 * (Rotate, Renew, Add 2FA). Built from metadata only: strength scores, dates, flags and
 * fingerprint groups — never a secret value.
 */
final readonly class HealthReport
{
    /** Category order, titles and the board's "why" lines. */
    public const array KINDS = [
        'weak' => ['Weak passwords', 'Short or easy to guess. Rotating swaps in a generated password.', 'Rotate'],
        'reused' => ['Reused passwords', 'The same password on more than one item. One leak opens both.', 'Rotate'],
        'old' => ['Older than 180 days', 'Not changed in six months. Rotate on a schedule, or set a reminder.', 'Rotate'],
        'breached' => ['Found in a breach', 'This password showed up in a public breach list. Change it first.', 'Rotate now'],
        'expiring' => ['Expiring soon', 'Tokens and domains that stop working if nobody renews them.', 'Renew'],
        'no2fa' => ['Items without 2FA', 'Adding a TOTP key means a stolen password alone can’t get in.', 'Add 2FA'],
    ];

    public function __construct(private VaultHealth $health, private VaultAccess $access) {}

    /**
     * @param  Collection<int, VaultItem>  $items  the viewer's visible items (VaultAccess::visibleItems)
     * @return array{score: int|null, grade: string|null, counts: array<string, int>, issues: int, affected: int, total: int, biggest: string|null, checkedAt: string|null, lists: list<array{key: string, title: string, why: string, action: string, rows: list<array{id: int, name: string, monogram: string, vault: string, detail: string, canFix: bool}>}>}
     */
    public function build(User $viewer, Collection $items): array
    {
        $summary = $this->health->summarise($items);
        $byId = $items->keyBy('id');
        $today = now()->startOfDay();
        $lists = [];
        $affected = [];

        foreach (self::KINDS as $key => [$title, $why, $action]) {
            $rows = [];

            foreach ($summary['ids'][$key] as $id) {
                /** @var VaultItem $item */
                $item = $byId->get($id);
                $affected[$id] = true;
                $rows[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'monogram' => $item->monogram(),
                    'vault' => $item->vault->name,
                    'detail' => $this->detail($key, $item, $summary['reused'][$item->id] ?? [], $byId, $today),
                    'canFix' => $this->access->can($viewer, $item, VaultAccessLevel::Edit),
                ];
            }

            $lists[] = ['key' => $key, 'title' => $title, 'why' => $why, 'action' => $action, 'rows' => $rows];
        }

        $checked = $items->max(fn (VaultItem $i) => $i->breach_checked_at?->getTimestamp());

        return [
            'score' => $summary['score'],
            'grade' => $summary['grade'],
            'counts' => $summary['counts'],
            'issues' => array_sum($summary['counts']),
            'affected' => count($affected),
            'total' => $items->count(),
            'biggest' => $this->biggest($summary['counts']),
            'checkedAt' => is_int($checked) ? now()->setTimestamp($checked)->toIso8601String() : null,
            'lists' => $lists,
        ];
    }

    /**
     * The category whose fixes would raise the score most ("Fixing … first gets you the biggest jump").
     *
     * @param  array<string, int>  $counts
     */
    private function biggest(array $counts): ?string
    {
        $best = null;
        $gain = 0;

        foreach (VaultHealth::WEIGHTS as $key => [$each, $cap]) {
            $points = min($cap, $each * ($counts[$key] ?? 0));

            if ($points > $gain) {
                [$best, $gain] = [$key, $points];
            }
        }

        return $best;
    }

    /**
     * @param  list<int>  $reusedWith
     * @param  \Illuminate\Support\Collection<int, VaultItem>  $byId
     */
    private function detail(string $key, VaultItem $item, array $reusedWith, \Illuminate\Support\Collection $byId, CarbonInterface $today): string
    {
        $vault = $item->vault->name.' vault';

        return match ($key) {
            'weak' => (PasswordStrength::label($item->strength) ?? 'Weak').' password · '.$vault,
            'reused' => $this->reusedText($reusedWith, $byId),
            'old' => $item->rotated_at === null ? 'Never changed' : 'Last changed '.$item->rotated_at->format('j M Y').' · '.(int) $item->rotated_at->startOfDay()->diffInDays($today).' days ago',
            'breached' => 'Seen in a breach list'.($item->breach_checked_at !== null ? ' on '.$item->breach_checked_at->format('j M Y') : '').' · '.$vault,
            'expiring' => $this->expiryText($item, $today),
            default => $vault.' · password only',
        };
    }

    /**
     * @param  list<int>  $ids
     * @param  \Illuminate\Support\Collection<int, VaultItem>  $byId
     */
    private function reusedText(array $ids, \Illuminate\Support\Collection $byId): string
    {
        $names = [];

        foreach ($ids as $id) {
            $other = $byId->get($id);

            if ($other instanceof VaultItem) {
                $names[] = $other->name;
            }
        }

        $hidden = count($ids) - count($names);

        if ($names === []) {
            return 'Same password as '.$hidden.' '.($hidden === 1 ? 'item' : 'items').' you can’t see';
        }

        return 'Same password as '.implode(', ', $names).($hidden > 0 ? " and {$hidden} more" : '');
    }

    private function expiryText(VaultItem $item, CarbonInterface $today): string
    {
        if ($item->expires_at === null) {
            return '';
        }

        $days = (int) $today->diffInDays($item->expires_at, false);
        $label = ItemTemplates::expiryLabel($item->type);
        $when = match (true) {
            $days < 0 => (-$days).' '.(-$days === 1 ? 'day' : 'days').' ago',
            $days === 0 => 'today',
            default => "in {$days} ".($days === 1 ? 'day' : 'days'),
        };

        return "{$label} ".$item->expires_at->format('j M Y')." · {$when}";
    }
}
