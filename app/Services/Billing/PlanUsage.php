<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InviteKind;
use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReached;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\VaultAttachment;
use App\Models\VaultItem;
use App\Models\Workspace;

/**
 * Live usage against the plan's soft limits (spec §20, D82), across the whole workspace:
 * companies, people (each counted once however many companies they're in), vault items
 * (attachments don't count) and stored files. Creating past a limit is refused with
 * PlanLimitReached, which opens the Upgrade modal; nothing that exists is ever touched.
 */
final class PlanUsage
{
    public function __construct(private readonly WorkspaceBilling $billing) {}

    /** @return array<string, array{used: int, limit: int|null}> keyed by PlanLimit value; storage in MB */
    public function usage(Workspace $workspace): array
    {
        $plan = $this->billing->for($workspace)->plan;
        $rows = [];

        foreach (PlanLimit::cases() as $limit) {
            $rows[$limit->value] = ['used' => $this->used($workspace, $limit), 'limit' => $plan?->limit($limit)];
        }

        return $rows;
    }

    public function used(Workspace $workspace, PlanLimit $limit): int
    {
        $companies = Company::query()->where('workspace_id', $workspace->id)->select('id');

        return match ($limit) {
            // Archived companies free their slot; deleted ones are soft-deleted (V4, D139).
            PlanLimit::Companies => Company::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->count(),
            PlanLimit::Users => Membership::query()->whereIn('company_id', $companies)->distinct()->count('user_id'),
            PlanLimit::VaultItems => VaultItem::query()->withoutGlobalScope('company')->whereIn('company_id', $companies)->count(),
            PlanLimit::Storage => (int) ceil($this->storageBytes($companies) / 1_048_576),
        };
    }

    /** Stored files in bytes: income attachments, expense receipts and vault attachments. */
    public function storageBytes(mixed $companies): int
    {
        return (int) Income::query()->withoutGlobalScope('company')->whereIn('company_id', $companies)->sum('attachment_size')
            + (int) Expense::query()->withoutGlobalScope('company')->whereIn('company_id', $companies)->sum('receipt_size')
            + (int) VaultAttachment::query()->withoutGlobalScope('company')->whereIn('company_id', $companies)->sum('size');
    }

    /** Whether one more fits (for flows that explain the limit their own way). */
    public function hasRoomFor(Workspace $workspace, PlanLimit $limit, ?int $userId = null): bool
    {
        // Someone already in another company of the workspace is counted once already.
        if ($limit === PlanLimit::Users && $userId !== null && Membership::query()
            ->whereIn('company_id', Company::query()->where('workspace_id', $workspace->id)->select('id'))
            ->where('user_id', $userId)->exists()) {
            return true;
        }

        try {
            $this->ensureCanAdd($workspace, $limit);

            return true;
        } catch (PlanLimitReached) {
            return false;
        }
    }

    /**
     * Refuses to add `$adding` more past the plan's limit. Users also count email invites
     * still waiting, so a batch of invites can't overshoot.
     *
     * @throws PlanLimitReached
     */
    public function ensureCanAdd(Workspace $workspace, PlanLimit $limit, int $adding = 1): void
    {
        $state = $this->billing->for($workspace);
        $plan = $state->plan;
        $max = $plan?->limit($limit);

        if ($plan === null || $max === null || ! $state->isManaged()) {
            return;
        }

        $used = $this->used($workspace, $limit);

        if ($limit === PlanLimit::Users) {
            $used += Invite::query()->withoutGlobalScope('company')
                ->whereIn('company_id', Company::query()->where('workspace_id', $workspace->id)->select('id'))
                ->where('kind', InviteKind::Email->value)
                ->pending()
                ->count();
        }

        if ($used + $adding > $max) {
            throw new PlanLimitReached($limit, $used, $max, $plan->name);
        }
    }
}
