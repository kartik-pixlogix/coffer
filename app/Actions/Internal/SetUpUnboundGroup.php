<?php

declare(strict_types=1);

namespace App\Actions\Internal;

use App\Actions\Companies\SeedCompanyPermissions;
use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Enums\VaultType;
use App\Ledger\ChartOfAccounts;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Our own workspace (D145): Unbound Group of Companies with Unbound Solutions, Services and
 * Innovation, their partners and equity as in the group's corporate overview, and a login
 * for each partner. The workspace has no plan, so billing treats it as unmanaged: no
 * charge, no limits, never read-only (D80). Kartik owns it; Kartik, Karan and Shyam are
 * Admins of every company, the other partners are Partners.
 */
final class SetUpUnboundGroup
{
    public const string WORKSPACE = 'Unbound Group of Companies';

    /** Everyone in the group, by first name (the corporate overview gives first names only). */
    public const array PEOPLE = ['Kartik', 'Karan', 'Shyam', 'Khyati', 'Faiz', 'Harshal'];

    public const array ADMINS = ['Kartik', 'Karan', 'Shyam'];

    /** Short code → name and partners in order with equity in basis points. */
    public const array COMPANIES = [
        'SOL' => [
            'name' => 'Unbound Solutions',
            'partners' => ['Khyati' => 2500, 'Karan' => 2500, 'Shyam' => 2500, 'Kartik' => 2500],
        ],
        'SVC' => [
            'name' => 'Unbound Services',
            'partners' => ['Faiz' => 5100, 'Khyati' => 1225, 'Karan' => 1225, 'Shyam' => 1225, 'Kartik' => 1225],
        ],
        'INN' => [
            'name' => 'Unbound Innovation',
            'partners' => ['Harshal' => 5100, 'Karan' => 1225, 'Khyati' => 1225, 'Shyam' => 1225, 'Kartik' => 1225],
        ],
    ];

    public function __construct(
        private readonly SeedCompanyPermissions $seedPermissions,
        private readonly SetCompanyRole $setRole,
        private readonly CurrentCompany $current,
        private readonly ChartOfAccounts $chart,
        private readonly UnboundSampleBooks $sample,
    ) {}

    /**
     * @return list<array{name: string, email: string, password: string|null}> a null password: the login already existed and keeps its own
     */
    public function handle(string $domain, bool $withSample): array
    {
        if (Workspace::query()->where('name', self::WORKSPACE)->exists()) {
            throw new RuntimeException(self::WORKSPACE.' is already set up.');
        }

        return DB::transaction(function () use ($domain, $withSample): array {
            $logins = [];
            /** @var array<string, User> $users */
            $users = [];

            foreach (self::PEOPLE as $name) {
                $email = Str::lower($name).'@'.$domain;
                $user = User::query()->where('email', $email)->first();
                $password = null;

                if ($user === null) {
                    $password = Str::password(16, symbols: false);
                    $user = User::query()->forceCreate([
                        'name' => $name,
                        'email' => $email,
                        'email_verified_at' => now(),
                        'password' => $password,
                        'remember_token' => Str::random(10),
                    ]);
                }

                $users[$name] = $user;
                $logins[] = ['name' => $name, 'email' => $email, 'password' => $password];
            }

            $workspace = Workspace::query()->create([
                'name' => self::WORKSPACE,
                'owner_id' => $users['Kartik']->id,
                // No plan and no trial: unmanaged, so never billed or limited (BillingState).
                'plan_id' => null,
                'trial_ends_at' => null,
            ]);

            foreach (self::COMPANIES as $code => $definition) {
                $company = $this->company($workspace, $code, $definition, $users);

                if ($withSample) {
                    $this->current->run($company, fn () => $this->sample->post($company, $code, $users['Kartik']));
                }
            }

            activity('companies')->performedOn($workspace)->causedBy($users['Kartik'])->log('set up the Unbound group workspace');

            return $logins;
        });
    }

    /**
     * @param  array{name: string, partners: array<string, int>}  $definition
     * @param  array<string, User>  $users
     */
    private function company(Workspace $workspace, string $code, array $definition, array $users): Company
    {
        if (array_sum($definition['partners']) !== 10_000) {
            throw new RuntimeException("{$definition['name']}: equity must add up to 100%.");
        }

        $company = Company::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $definition['name'],
            'short_code' => $code,
            'type' => CompanyType::Single,
            'currency' => 'INR',
            'fy_start_month' => 4,
            'attribution_mode' => CompanyType::Single->defaultAttribution(),
            'opening_balance_paise' => 0,
            'legal_name' => $definition['name'],
        ]);

        $this->seedPermissions->handle($company);

        $this->current->run($company, function () use ($company, $definition, $users): void {
            $position = 0;

            foreach ($definition['partners'] as $name => $bps) {
                $role = in_array($name, self::ADMINS, true) ? CompanyRole::Admin : CompanyRole::Partner;

                Partner::query()->create([
                    'user_id' => $users[$name]->id,
                    'name' => $name,
                    'email' => $users[$name]->email,
                    'role' => $role,
                    'colour' => $position + 1,
                    'equity_bps' => $bps,
                    'position' => $position,
                ]);
                $this->setRole->handle($company, $users[$name], $role);
                $position++;
            }
        });

        // The books (categories, money accounts, chart of accounts) and an empty vault, as Launch does.
        $this->chart->setUp($company);
        Vault::query()->create(['company_id' => $company->id, 'name' => 'Internal', 'type' => VaultType::Shared, 'created_by' => $users['Kartik']->id]);

        return $company;
    }
}
