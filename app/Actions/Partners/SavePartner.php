<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Invites\IssueInvite;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Support\Equity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a partner from Settings › Partners & equity (spec §16). Because shares
 * must still total 100% per side (or per company), the same save carries the new
 * shares of the other partners in the affected split (D31). A new partner with an
 * email gets an invite, as in the wizard (D23).
 */
final class SavePartner
{
    public function __construct(
        private readonly SetCompanyRole $setRole,
        private readonly IssueInvite $issueInvite,
    ) {}

    /**
     * @param  array{name: string, email?: string|null, role: string, colour: int, side_id?: int|null, equity: string, shares?: array<int|string, string>}  $data
     */
    public function handle(Company $company, User $actor, ?Partner $partner, array $data): Partner
    {
        return DB::transaction(function () use ($company, $actor, $partner, $data): Partner {
            /** @var Collection<int, Partner> $active */
            $active = Partner::query()->active()->lockForUpdate()->get()->keyBy('id');
            $sideId = $company->isJv() ? (int) ($data['side_id'] ?? 0) : null;

            if ($company->isJv() && ! Side::query()->whereKey($sideId)->exists()) {
                throw ValidationException::withMessages(['side_id' => 'Choose a side for this partner.']);
            }

            $role = CompanyRole::from($data['role']);
            $equity = Equity::parse($data['equity']) ?? 0;

            // The proposed split: this partner's new values plus any rebalanced shares.
            /** @var array<int, array{side_id: int|null, bps: int, name: string}> $proposed */
            $proposed = $active->map(fn (Partner $p) => ['side_id' => $p->side_id, 'bps' => $p->equity_bps, 'name' => $p->name])->all();

            foreach ($data['shares'] ?? [] as $id => $share) {
                if (isset($proposed[(int) $id]) && (int) $id !== $partner?->id) {
                    $proposed[(int) $id]['bps'] = Equity::parse($share) ?? 0;
                }
            }

            $proposed[$partner === null ? 0 : $partner->id] = ['side_id' => $sideId, 'bps' => $equity, 'name' => $data['name']];

            $this->checkTotals($company, collect($proposed));

            $partner ??= new Partner(['company_id' => $company->id, 'position' => ($active->max('position') ?? 0) + 1]);
            $roleChanged = $partner->exists && $partner->role !== $role;

            $partner->fill([
                'name' => trim($data['name']),
                'role' => $role,
                'colour' => $data['colour'],
                'side_id' => $sideId,
                'equity_bps' => $equity,
            ]);

            if (! $partner->exists) {
                $partner->email = filled($data['email'] ?? null) ? strtolower(trim((string) $data['email'])) : null;
            }

            $isNew = ! $partner->exists;
            $partner->save();

            foreach ($data['shares'] ?? [] as $id => $share) {
                if ((int) $id !== $partner->id && $active->has((int) $id)) {
                    $active[(int) $id]->update(['equity_bps' => Equity::parse($share) ?? 0]);
                }
            }

            // A linked user's role follows their partner record.
            if ($roleChanged && $partner->user !== null && ! $company->workspace->isOwnedBy($partner->user)) {
                $this->setRole->handle($company, $partner->user, $role);
            }

            if ($isNew && $partner->email !== null) {
                $this->issueInvite->email($company, $actor, $partner->email, $role, partnerId: $partner->id);
            }

            activity('partners')->performedOn($partner)->causedBy($actor)
                ->withProperties(['equity_bps' => $equity, 'side_id' => $sideId])
                ->log($isNew ? 'added a partner' : 'updated a partner');

            return $partner;
        });
    }

    /**
     * 100% per side in a JV (sides with partners), or across the company when Single.
     *
     * @param  Collection<int, array{side_id: int|null, bps: int, name: string}>  $proposed
     */
    private function checkTotals(Company $company, Collection $proposed): void
    {
        if (! $company->isJv()) {
            $total = (int) $proposed->sum('bps');

            if ($total !== Equity::WHOLE) {
                throw ValidationException::withMessages(['equity' => Equity::gapMessage($total)]);
            }

            return;
        }

        $sides = Side::query()->get()->keyBy('id');

        foreach ($proposed->groupBy('side_id') as $sideId => $rows) {
            $total = (int) $rows->sum('bps');

            if ($total !== Equity::WHOLE) {
                $side = $sides->get((int) $sideId);
                $name = $side === null ? 'This side' : $side->name;

                throw ValidationException::withMessages(['equity' => Equity::gapMessage($total, "{$name} partners", 'this side totals 100%')]);
            }
        }
    }
}
