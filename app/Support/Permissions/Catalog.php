<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use App\Enums\CompanyRole;

/**
 * Every page and action in the per-company permission matrix, with the spec §2.1 / §2.2
 * defaults (D24). Permission names are `page.<key>` and `action.<key>`. Admins always
 * have everything, so only Partner, Member and Viewer defaults are listed.
 *
 * "Own" rows (a Member's own timesheets, own expense submissions, vault items shared
 * with them) are ticked here; each module's policies narrow them to the user's records.
 */
final class Catalog
{
    /**
     * @var array<string, array{label: string, defaults: array{partner: bool, member: bool, viewer: bool}, note?: string, locked?: bool}>
     */
    public const array PAGES = [
        'dashboard' => ['label' => 'Dashboard', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => true]],
        'partners' => ['label' => 'Partners', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'team' => ['label' => 'Team', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members see their own profile'],
        'timesheets' => ['label' => 'Timesheets', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members see their own entries'],
        'income' => ['label' => 'Income', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'expenses' => ['label' => 'Expenses', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members see their own submissions'],
        'capital_accounts' => ['label' => 'Capital accounts', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'partner_loans' => ['label' => 'Partner loans', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'settlements' => ['label' => 'Settlements', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false], 'note' => 'Joint ventures only'],
        'reports' => ['label' => 'Reports', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => true]],
        'trial_balance' => ['label' => 'Trial balance', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'balance_sheet' => ['label' => 'Balance sheet', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => true]],
        'journal' => ['label' => 'Journal', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'audit_trail' => ['label' => 'Audit trail', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'vault' => ['label' => 'Credentials vault', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members see items shared with them'],
        'settings' => ['label' => 'Settings', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        // Billing belongs to the workspace owner and Admins; it can't be granted (D29).
        'billing' => ['label' => 'Billing', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false], 'locked' => true, 'note' => 'Workspace owner and Admins only'],
    ];

    /**
     * @var array<string, array{label: string, group: string, defaults: array{partner: bool, member: bool, viewer: bool}, note?: string}>
     */
    public const array ACTIONS = [
        'income.manage' => ['label' => 'Add and edit income', 'group' => 'Money', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'expenses.create' => ['label' => 'Add expenses', 'group' => 'Money', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members add their own'],
        'expenses.approve' => ['label' => 'Approve and reject expenses', 'group' => 'Money', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false], 'note' => 'Never their own'],
        'settlements.record' => ['label' => 'Record settlement transfers', 'group' => 'Money', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        'capital.manage' => ['label' => 'Manage capital and loans', 'group' => 'Money', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        'time.log' => ['label' => 'Log time', 'group' => 'People', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Members log their own'],
        'rates.edit' => ['label' => 'Edit rates', 'group' => 'People', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        'team.manage' => ['label' => 'Manage team', 'group' => 'People', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        'fy.close' => ['label' => 'Close fiscal year', 'group' => 'People', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
        'vault.create' => ['label' => 'Create items', 'group' => 'Vault', 'defaults' => ['partner' => true, 'member' => false, 'viewer' => false]],
        'vault.reveal' => ['label' => 'Reveal and copy', 'group' => 'Vault', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'With Use access or more on the item'],
        'vault.share' => ['label' => 'Share and manage', 'group' => 'Vault', 'defaults' => ['partner' => true, 'member' => true, 'viewer' => false], 'note' => 'Item Managers only'],
        'vault.export' => ['label' => 'Export and view audit log', 'group' => 'Vault', 'defaults' => ['partner' => false, 'member' => false, 'viewer' => false]],
    ];

    /** Roles whose column is editable (Admin is locked to full access). */
    public const array EDITABLE_ROLES = [CompanyRole::Partner, CompanyRole::Member, CompanyRole::Viewer];

    public static function pagePermission(string $page): string
    {
        return 'page.'.$page;
    }

    public static function actionPermission(string $action): string
    {
        return 'action.'.$action;
    }

    /** @return list<string> every permission name */
    public static function all(): array
    {
        return [
            ...array_map(self::pagePermission(...), array_keys(self::PAGES)),
            ...array_map(self::actionPermission(...), array_keys(self::ACTIONS)),
        ];
    }

    /** @return list<string> the permission names a role gets by default */
    public static function defaultsFor(CompanyRole $role): array
    {
        if ($role === CompanyRole::Admin) {
            return self::all();
        }

        $names = [];

        foreach (self::PAGES as $key => $page) {
            if ($page['defaults'][$role->value]) {
                $names[] = self::pagePermission($key);
            }
        }

        foreach (self::ACTIONS as $key => $action) {
            if ($action['defaults'][$role->value]) {
                $names[] = self::actionPermission($key);
            }
        }

        return $names;
    }

    public static function isLocked(string $permission): bool
    {
        return $permission === self::pagePermission('billing');
    }

    /** Where each role lands after sign-in (spec §2.1): Members on Timesheets, everyone else the Dashboard. */
    public static function landingPageFor(CompanyRole $role): string
    {
        return $role === CompanyRole::Member ? 'timesheets' : 'dashboard';
    }
}
