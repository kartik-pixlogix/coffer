/** Page keys of the permission matrix (App\Support\Permissions\Catalog::PAGES). */
export type PageKey =
    | 'dashboard'
    | 'partners'
    | 'team'
    | 'timesheets'
    | 'income'
    | 'expenses'
    | 'capital_accounts'
    | 'partner_loans'
    | 'settlements'
    | 'reports'
    | 'trial_balance'
    | 'balance_sheet'
    | 'audit_trail'
    | 'vault'
    | 'settings'
    | 'billing';

/** Action keys of the permission matrix (Catalog::ACTIONS). */
export type ActionKey =
    | 'income.manage'
    | 'expenses.create'
    | 'expenses.approve'
    | 'settlements.record'
    | 'capital.manage'
    | 'time.log'
    | 'rates.edit'
    | 'team.manage'
    | 'fy.close'
    | 'vault.create'
    | 'vault.reveal'
    | 'vault.share'
    | 'vault.export';

/** What the signed-in user's role may open and do in the current company. */
export type Can = {
    pages: Partial<Record<PageKey, boolean>>;
    actions: Partial<Record<ActionKey, boolean>>;
};
