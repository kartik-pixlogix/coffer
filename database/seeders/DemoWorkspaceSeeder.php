<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Companies\SeedCompanyPermissions;
use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Enums\CostType;
use App\Enums\InviteKind;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Side;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * The design's sample data (spec §24): Vertex Group with Saarthi Portal JV (Vertex Labs
 * and Bluefin Infotech sides), Vertex Labs Pvt Ltd, Northstar Studio and Kriya Commerce,
 * their partners, users and team. Every demo login uses the password "password".
 * Money (income, expenses, settlements) arrives with the ledger in Phase 3.
 */
class DemoWorkspaceSeeder extends Seeder
{
    use Concerns\DemoOnly;

    public function __construct(
        private readonly SeedCompanyPermissions $seedPermissions,
        private readonly SetCompanyRole $setRole,
        private readonly CurrentCompany $current,
    ) {}

    public function run(): void
    {
        $this->refuseInProduction();

        $aarav = $this->user('Aarav Mehta', 'aarav@vertexlabs.in');
        $ishita = $this->user('Ishita Rao', 'ishita@vertexlabs.in');
        $kabir = $this->user('Kabir Shah', 'kabir@bluefininfotech.in');
        $meera = $this->user('Meera Iyer', 'meera@bluefininfotech.in');
        $rohan = $this->user('Rohan Desai', 'rohan@vertexlabs.in');
        $accounts = $this->user('Ledgerworks Accounts', 'accounts@ledgerworks.in');

        $workspace = Workspace::query()->create([
            'name' => 'Vertex Group',
            'owner_id' => $aarav->id,
            'trial_ends_at' => now()->addDays(Workspace::TRIAL_DAYS),
            // D80: on the Growth trial, which the demo data fits (4 of 5 companies: "Near the limit").
            'plan_id' => Plan::query()->where('key', 'growth')->value('id'),
            'billing_cycle' => 'monthly',
            'billing_currency' => 'inr',
            'billing_email' => 'accounts@vertexlabs.in',
            'billing_name' => 'Vertex Labs Pvt Ltd, Pune',
            'billing_gstin' => '27ABCDE1234F1Z5',
        ]);

        $this->saarthi($workspace, $aarav, $ishita, $kabir, $meera, $rohan, $accounts);

        $this->single($workspace, 'Vertex Labs Pvt Ltd', 'VL', 2_50_000_00, [
            [$aarav, CompanyRole::Admin, 1, 6000],
            [$ishita, CompanyRole::Partner, 2, 4000],
        ]);

        $this->single($workspace, 'Northstar Studio', 'NS', 0, [
            [$aarav, CompanyRole::Admin, 1, 7000],
            [$meera, CompanyRole::Partner, 4, 3000],
        ]);

        $this->single($workspace, 'Kriya Commerce', 'KC', 0, [
            [$aarav, CompanyRole::Admin, 1, 5000],
            [$kabir, CompanyRole::Partner, 3, 5000],
        ]);

        // A super-admin for /admin (D85), local only: never a tenant user. Its authenticator
        // app uses the demo 2FA key (D94), so `coffer:demo-2fa` prints its codes too.
        if (app()->environment('local')) {
            PlatformAdmin::query()->updateOrCreate(
                ['email' => 'platform@coffer.test'],
                ['name' => 'Coffer Platform', 'password' => 'platform-password'],
            )->saveAppAuthenticationSecret(DemoVaultSeeder::DEMO_2FA_KEY);
        }
    }

    private function saarthi(Workspace $workspace, User $aarav, User $ishita, User $kabir, User $meera, User $rohan, User $accounts): void
    {
        $company = $this->company($workspace, 'Saarthi Portal JV', 'SPJV', CompanyType::Jv, 0);
        // SettingsCompany.dc.html's details.
        $company->update([
            'legal_name' => 'Saarthi Portal Joint Venture',
            'gstin' => '27ABCDE1234F1Z5',
            'address' => 'Office 402, Baner Road, Pune, Maharashtra 411045',
        ]);

        $this->current->run($company, function () use ($company, $aarav, $ishita, $kabir, $meera, $rohan, $accounts): void {
            $vertex = Side::query()->create(['name' => 'Vertex Labs', 'equity_bps' => 5000, 'position' => 0]);
            $bluefin = Side::query()->create(['name' => 'Bluefin Infotech', 'equity_bps' => 5000, 'position' => 1]);

            $partners = [];

            foreach ([
                [$aarav, CompanyRole::Admin, 1, $vertex],
                [$ishita, CompanyRole::Partner, 2, $vertex],
                [$kabir, CompanyRole::Partner, 3, $bluefin],
                [$meera, CompanyRole::Partner, 4, $bluefin],
            ] as $position => [$user, $role, $colour, $side]) {
                $partners[$user->name] = Partner::query()->create([
                    'side_id' => $side->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'colour' => $colour,
                    'equity_bps' => 5000,
                    'position' => $position,
                ]);
                $this->setRole->handle($company, $user, $role);
            }

            $team = [
                ['Rohan Desai', 'Senior Developer', CostType::Hourly, 500_00, 'Aarav Mehta', $rohan],
                ['Neha Joshi', 'QA Engineer', CostType::Hourly, 200_00, 'Ishita Rao', null],
                ['Sana Qureshi', 'iOS Developer', CostType::Hourly, 450_00, 'Kabir Shah', null],
                ['Dev Patel', 'Project Manager', CostType::FixedMonthly, 40_000_00, 'Kabir Shah', null],
                ['Vivaan Kapoor', 'UI Designer', CostType::FixedMonthly, 35_000_00, 'Meera Iyer', null],
                ['Arjun Nair', 'Backend Developer', CostType::Hourly, 400_00, 'Aarav Mehta', null],
                ['Priya Menon', 'Content Writer', CostType::Hourly, 250_00, 'Ishita Rao', null],
                ['Tanvi Shah', 'Android Developer', CostType::Hourly, 420_00, 'Meera Iyer', null],
            ];

            /** @var array<string, Member> $members */
            $members = [];

            foreach ($team as [$name, $designation, $costType, $rate, $partner, $user]) {
                $members[$name] = Member::query()->create([
                    'partner_id' => $partners[$partner]->id,
                    'user_id' => $user?->id,
                    'name' => $name,
                    'designation' => $designation,
                    'email' => $user?->email,
                    'cost_type' => $costType,
                    'rate_paise' => $rate,
                ]);
            }

            $this->setRole->handle($company, $rohan, CompanyRole::Member);
            $this->setRole->handle($company, $accounts, CompanyRole::Viewer);

            // Everyone opens Saarthi first, as on the designs.
            $company->memberships()->update(['last_active_at' => now()]);

            // A pending invite for Priya (linked to her team record) and a share link.
            Invite::query()->create([
                'kind' => InviteKind::Email,
                'email' => 'priya@vertexlabs.in',
                'role' => CompanyRole::Member,
                'member_id' => $members['Priya Menon']->id,
                'invited_by' => $aarav->id,
                ...Invite::tokenAttributes(Invite::newToken()),
                'expires_at' => now()->addDays(5),
                'last_sent_at' => now()->subDays(2),
            ]);

            Invite::query()->create([
                'kind' => InviteKind::Link,
                'role' => CompanyRole::Member,
                'invited_by' => $aarav->id,
                ...Invite::tokenAttributes(Invite::newToken()),
                'expires_at' => now()->addDays(3),
                'uses' => 0,
            ]);
        });
    }

    /**
     * @param  list<array{0: User, 1: CompanyRole, 2: int, 3: int}>  $partners  user, role, colour, equity bps
     */
    private function single(Workspace $workspace, string $name, string $code, int $openingPaise, array $partners): void
    {
        $company = $this->company($workspace, $name, $code, CompanyType::Single, $openingPaise);

        $this->current->run($company, function () use ($company, $partners): void {
            foreach ($partners as $position => [$user, $role, $colour, $bps]) {
                Partner::query()->create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'colour' => $colour,
                    'equity_bps' => $bps,
                    'position' => $position,
                ]);
                $this->setRole->handle($company, $user, $role);
            }
        });
    }

    private function company(Workspace $workspace, string $name, string $code, CompanyType $type, int $openingPaise): Company
    {
        $company = Company::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'short_code' => $code,
            'type' => $type,
            'currency' => 'INR',
            'fy_start_month' => 4,
            'attribution_mode' => $type->defaultAttribution(),
            'opening_balance_paise' => $openingPaise,
        ]);

        $this->seedPermissions->handle($company);

        return $company;
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }
}
