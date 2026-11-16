<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VaultAccessLevel;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\VaultItem;
use App\Notifications\Vault\VaultItemExpiring;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Expiry alerts (spec §14.4, D74): items whose `expires_at` is exactly 30, 7 or 1 days
 * away notify everyone who can Edit them (they can renew it). Runs daily; a second run
 * on the same day sends nothing twice.
 */
final class VaultExpiryAlerts extends Command
{
    public const array DAYS = [30, 7, 1];

    protected $signature = 'coffer:vault-expiry-alerts';

    protected $description = 'Notify people about vault items expiring in 30, 7 or 1 days';

    public function handle(CurrentCompany $current, VaultAccess $access): int
    {
        $sent = 0;
        $today = now()->startOfDay();
        $dates = array_map(fn (int $d): string => $today->addDays($d)->toDateString(), self::DAYS);

        $companyIds = VaultItem::query()->withoutGlobalScope('company')->whereIn('expires_at', $dates)->distinct()->pluck('company_id');

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $sent += (int) $current->run($company, function () use ($company, $access, $dates, $today): int {
                $access->flush();
                $sent = 0;
                $people = Membership::query()->with('user')->where('company_id', $company->id)->get()
                    ->map(fn (Membership $m): User => $m->user);

                foreach (VaultItem::query()->with('vault')->whereIn('expires_at', $dates)->get() as $item) {
                    $days = $item->expires_at === null ? 0 : (int) $today->diffInDays($item->expires_at);

                    if (! Cache::add("vault-expiry:{$item->id}:{$days}:{$today->toDateString()}", true, now()->addDays(2))) {
                        continue;
                    }

                    $to = $people->filter(fn (User $user): bool => $access->can($user, $item, VaultAccessLevel::Edit))->values();
                    Notification::send($to, VaultItemExpiring::for($item, $days));
                    $sent += $to->count();
                }

                return $sent;
            });
        }

        $this->info("Sent {$sent} expiry ".($sent === 1 ? 'alert' : 'alerts').'.');

        return self::SUCCESS;
    }
}
