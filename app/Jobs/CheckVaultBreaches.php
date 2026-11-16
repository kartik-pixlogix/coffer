<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\VaultAccessLevel;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\VaultItem;
use App\Notifications\Vault\VaultBreachFound;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\PwnedPasswords;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultPolicy;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * The optional breach check (spec §14.3, D74), queued: for each item's main password,
 * ask Have I Been Pwned by k-anonymity and store only `breached` and `breach_checked_at`.
 * The payload is a company id and item ids; passwords are decrypted inside the job and
 * never leave it except as a 5-character hash prefix. A newly breached item notifies
 * the people who can change it.
 */
final class CheckVaultBreaches implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>|null  $itemIds  null = every item in the company */
    public function __construct(public readonly int $companyId, public readonly ?array $itemIds = null) {}

    public function handle(CurrentCompany $current, ItemSecrets $secrets, PwnedPasswords $pwned, VaultAccess $access): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null || ! PwnedPasswords::enabled() || ! VaultPolicy::for($company)->breachChecks) {
            return;
        }

        $current->run($company, function () use ($secrets, $pwned, $access): void {
            $items = VaultItem::query()->with('vault')
                ->when($this->itemIds !== null, fn ($q) => $q->whereIn('id', $this->itemIds ?? []))
                ->get();

            foreach ($items as $item) {
                $primary = ItemTemplates::primary($item->type);

                if ($primary === null || ! ItemTemplates::isFingerprinted($item->type, $primary)) {
                    continue;
                }

                $password = $secrets->get($item, $primary);
                $found = $password === null ? null : $pwned->isPwned($password);
                unset($password);

                if ($found === null) {
                    continue;
                }

                $was = $item->breached;
                VaultItem::query()->whereKey($item->id)->update(['breached' => $found, 'breach_checked_at' => now()]);

                if ($found && ! $was) {
                    $access->flush();
                    $people = Membership::query()->with('user')->where('company_id', $item->company_id)->get()
                        ->map(fn (Membership $m): User => $m->user)
                        ->filter(fn (User $user): bool => $access->can($user, $item, VaultAccessLevel::Edit))
                        ->values();
                    Notification::send($people, VaultBreachFound::for($item));
                }
            }
        });
    }
}
