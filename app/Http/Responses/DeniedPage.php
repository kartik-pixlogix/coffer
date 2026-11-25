<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\CompanyRole;
use App\Models\AccessRequest;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use App\Support\Permissions\Catalog;
use App\Support\Permissions\PageRoutes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Permission denied" page (StateDenied.dc.html), rendered inside the app shell
 * for a direct visit to a page the role can't open, or any other 403.
 */
final class DeniedPage
{
    /** Why a page is restricted, for "{why}, so in {company} they're for …". */
    private const array WHY = [
        'dashboard' => 'The dashboard shows company-wide money',
        'partners' => 'Partners shows everyone’s equity and shares',
        'team' => 'Team shows everyone’s rates and costs',
        'timesheets' => 'Timesheets show everyone’s hours',
        'income' => 'Income shows company-wide money',
        'expenses' => 'Expenses show company-wide spending',
        'capital_accounts' => 'Capital accounts show each partner’s money in the company',
        'partner_loans' => 'Partner loans show money owed between partners and the company',
        'settlements' => 'Settlements show what each side owes the other',
        'reports' => 'Reports show company-wide money',
        'trial_balance' => 'The trial balance shows the whole ledger',
        'balance_sheet' => 'The balance sheet shows the whole company’s position',
        'cash_flow' => 'The cash flow statement shows where the company’s cash went',
        'chart_of_accounts' => 'The chart of accounts shows every account’s balance',
        'journal' => 'The journal shows every entry in the books',
        'audit_trail' => 'The audit trail shows every change to the books, by whom',
        'vault' => 'The vault holds the team’s logins',
        'settings' => 'Settings change how the whole company works',
        'billing' => 'Billing holds the workspace’s plan and invoices',
    ];

    /** What each role covers, for "You're a Member, which covers …". */
    private const array COVERS = [
        'partner' => 'the company’s finances and reports',
        'member' => 'your own hours, expenses and the vault items shared with you',
        'viewer' => 'read-only reports and statements',
    ];

    public static function render(Request $request, ?string $page): Response
    {
        return Inertia::render('errors/403', self::props($request, $page))
            ->toResponse($request)
            ->setStatusCode(403);
    }

    /** @return array<string, mixed> */
    public static function props(Request $request, ?string $page): array
    {
        $current = app(CurrentCompany::class);
        $company = $current->get();
        $user = $request->user();
        $role = $current->role();

        if ($company === null || ! $user instanceof User || $role === null) {
            return ['page' => null, 'pageLabel' => null, 'explanation' => null, 'canOpen' => [], 'admins' => [], 'request' => null, 'landing' => null];
        }

        $pageLabel = $page !== null ? Catalog::PAGES[$page]['label'] : null;
        $allowed = app(Access::class)->pages($user);

        return [
            'page' => $page,
            'pageLabel' => $pageLabel,
            'explanation' => $page !== null ? self::explanation($page, $company->name, $role) : null,
            'canOpen' => self::canOpen($allowed, $role),
            'admins' => $company->admins()->reject(fn (User $admin) => $admin->id === $user->id)->pluck('name')->values()->all(),
            'request' => $page === null ? null : AccessRequest::query()
                ->where('user_id', $user->id)
                ->where('page', $page)
                ->whereNull('cancelled_at')
                ->latest()
                ->first(['id', 'reason']),
            'landing' => self::landing($role, $allowed),
        ];
    }

    /**
     * Where "Go to …" leads: the role's landing page when it exists, else Profile.
     *
     * @param  array<string, bool>  $allowed
     * @return array{url: string, label: string}
     */
    public static function landing(CompanyRole $role, array $allowed): array
    {
        $page = Catalog::landingPageFor($role);

        if (($allowed[$page] ?? false) && PageRoutes::url($page) !== null) {
            return ['url' => route('home'), 'label' => $page === 'timesheets' ? 'Go to my timesheets' : 'Go to the dashboard'];
        }

        return ['url' => route('profile.edit'), 'label' => 'Go to my profile'];
    }

    private static function explanation(string $page, string $company, CompanyRole $role): string
    {
        $roles = [CompanyRole::Admin->label().'s'];

        foreach (Catalog::EDITABLE_ROLES as $editable) {
            $permission = Catalog::pagePermission($page);
            $spatieRole = Role::query()
                ->where('name', $editable->value)
                ->where(config('permission.column_names.team_foreign_key'), app(CurrentCompany::class)->id())
                ->first();

            if (! Catalog::isLocked($permission) && $spatieRole?->hasPermissionTo($permission)) {
                $roles[] = $editable->label().'s';
            }
        }

        $who = count($roles) === 1 ? 'only for Admins' : 'for '.self::listing($roles);
        $covers = self::COVERS[$role->value] ?? 'everything';

        return self::WHY[$page].", so in {$company} they’re {$who}. You’re {$role->withArticle()}, which covers {$covers}.";
    }

    /**
     * @param  array<string, bool>  $allowed
     * @return list<array{page: string, label: string, url: string|null, note: string}>
     */
    private static function canOpen(array $allowed, CompanyRole $role): array
    {
        $member = $role === CompanyRole::Member;
        $notes = [
            'dashboard' => 'Company numbers',
            'partners' => 'Equity and shares',
            'team' => $member ? 'Your profile' : 'People and rates',
            'timesheets' => $member ? 'Your hours' : 'Hours logged',
            'income' => 'Money in',
            'expenses' => $member ? 'Add and view yours' : 'Spending and approvals',
            'capital_accounts' => 'Partner capital',
            'partner_loans' => 'Loans and repayments',
            'settlements' => 'Who owes whom',
            'reports' => 'Statements and charts',
            'trial_balance' => 'The whole ledger',
            'balance_sheet' => 'Assets and liabilities',
            'cash_flow' => 'Where the cash went',
            'chart_of_accounts' => 'Accounts and balances',
            'journal' => 'Every voucher',
            'audit_trail' => 'Every change, by whom',
            'vault' => $member ? 'Items shared with you' : 'Team logins',
            'settings' => 'Company settings',
            'billing' => 'Plan and invoices',
        ];

        $list = [];

        foreach ($allowed as $page => $ok) {
            if ($ok) {
                $list[] = [
                    'page' => $page,
                    'label' => Catalog::PAGES[$page]['label'],
                    'url' => PageRoutes::url($page),
                    'note' => $notes[$page],
                ];
            }
        }

        $list[] = ['page' => 'profile', 'label' => 'Profile', 'url' => route('profile.edit'), 'note' => 'Your account and security'];

        return $list;
    }

    /** @param list<string> $items */
    private static function listing(array $items): string
    {
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
