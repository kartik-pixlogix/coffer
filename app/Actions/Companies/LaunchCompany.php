<?php

declare(strict_types=1);

namespace App\Actions\Companies;

use App\Actions\Invites\IssueInvite;
use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Models\Company;
use App\Models\CompanyDraft;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentCompany;
use App\Support\Equity;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Launch" in the create-company wizard (spec §3.1): one transaction creates the company,
 * its roles and permission matrix, the creator's Admin membership, sides, partners,
 * team members, invites (D23), and the books: categories, money accounts, the chart of
 * accounts and the opening-balance entry.
 *
 * The first partner row is always the creating user, as Admin.
 */
final class LaunchCompany
{
    public function __construct(
        private readonly SeedCompanyPermissions $seedPermissions,
        private readonly SetCompanyRole $setRole,
        private readonly IssueInvite $issueInvite,
        private readonly CurrentCompany $current,
        private readonly ChartOfAccounts $chart,
        private readonly OpeningBalance $openingBalance,
    ) {}

    /**
     * @param  array{company: array<string, mixed>, sides?: list<array<string, mixed>>, partners: list<array<string, mixed>>, members?: list<array<string, mixed>>, invite_members?: bool}  $data
     */
    public function handle(Workspace $workspace, User $creator, array $data): Company
    {
        return DB::transaction(function () use ($workspace, $creator, $data): Company {
            $type = CompanyType::from((string) $data['company']['type']);

            $company = Company::query()->create([
                'workspace_id' => $workspace->id,
                'name' => trim((string) $data['company']['name']),
                'short_code' => Str::upper((string) $data['company']['short_code']),
                'type' => $type,
                'currency' => (string) $data['company']['currency'],
                'fy_start_month' => 4,
                'attribution_mode' => $type->defaultAttribution(),
                'opening_balance_paise' => Money::parseRupees($data['company']['opening_balance'] ?? '0') ?? 0,
            ]);

            $this->seedPermissions->handle($company);
            $this->setRole->handle($company, $creator, CompanyRole::Admin);

            $this->current->run($company, function () use ($company, $creator, $data, $type): void {
                $sideIds = $type === CompanyType::Jv ? $this->createSides($data['sides'] ?? []) : [];
                [$partnerIds, $partnerInvites] = $this->createPartners($creator, $data['partners'], $sideIds);
                $memberInvites = $this->createMembers($data['members'] ?? [], $partnerIds);

                foreach ($partnerInvites as [$partner, $email]) {
                    $this->issueInvite->email($company, $creator, $email, $partner->role, partnerId: $partner->id);
                }

                if ((bool) ($data['invite_members'] ?? false)) {
                    foreach ($memberInvites as [$member, $email]) {
                        $this->issueInvite->email($company, $creator, $email, CompanyRole::Member, memberId: $member->id);
                    }
                }
            });

            // The books: categories, money accounts, chart of accounts and the opening balance (spec §3.1).
            $this->chart->setUp($company);
            $this->openingBalance->post($company, by: $creator);

            CompanyDraft::query()->where('workspace_id', $workspace->id)->where('user_id', $creator->id)->delete();

            activity('companies')
                ->performedOn($company)
                ->causedBy($creator)
                ->withProperties(['type' => $type->value])
                ->log('launched the company');

            return $company;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sides
     * @return array<string, int> side key → id
     */
    private function createSides(array $sides): array
    {
        $ids = [];

        foreach ($sides as $position => $side) {
            $ids[(string) $side['key']] = Side::query()->create([
                'name' => trim((string) $side['name']),
                'equity_bps' => Equity::parse($side['equity']) ?? 0,
                'position' => $position,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $partners
     * @param  array<string, int>  $sideIds
     * @return array{0: array<string, int>, 1: list<array{0: Partner, 1: string}>}
     */
    private function createPartners(User $creator, array $partners, array $sideIds): array
    {
        $ids = [];
        $invites = [];

        foreach ($partners as $position => $row) {
            $isCreator = $position === 0;
            $email = $isCreator ? $creator->email : (filled($row['email'] ?? null) ? Str::lower((string) $row['email']) : null);

            $partner = Partner::query()->create([
                'side_id' => $sideIds[(string) ($row['side_key'] ?? '')] ?? null,
                'user_id' => $isCreator ? $creator->id : null,
                'name' => trim((string) $row['name']),
                'email' => $email,
                'role' => $isCreator ? CompanyRole::Admin : CompanyRole::from((string) $row['role']),
                'colour' => (int) $row['colour'],
                'equity_bps' => Equity::parse($row['equity']) ?? 0,
                'position' => $position,
            ]);

            $ids[(string) $row['key']] = $partner->id;

            if (! $isCreator && $email !== null) {
                $invites[] = [$partner, $email];
            }
        }

        return [$ids, $invites];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, int>  $partnerIds
     * @return list<array{0: Member, 1: string}>
     */
    private function createMembers(array $members, array $partnerIds): array
    {
        $invites = [];

        foreach ($members as $row) {
            $email = filled($row['email'] ?? null) ? Str::lower((string) $row['email']) : null;

            $member = Member::query()->create([
                'partner_id' => $partnerIds[(string) $row['partner_key']],
                'name' => trim((string) $row['name']),
                'designation' => filled($row['designation'] ?? null) ? trim((string) $row['designation']) : null,
                'email' => $email,
                'cost_type' => CostType::from((string) $row['cost_type']),
                'rate_paise' => Money::parseRupees($row['rate']) ?? 0,
                'status' => MemberStatus::Active,
            ]);

            if ($email !== null) {
                $invites[] = [$member, $email];
            }
        }

        return $invites;
    }
}
