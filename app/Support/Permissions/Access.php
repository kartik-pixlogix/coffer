<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use App\Models\User;
use App\Support\CurrentCompany;

/**
 * Answers "may this user open / do X in the current company?" from the company's
 * permission matrix (spatie team roles). Wired into the Gate, so `$user->can('page.reports')`,
 * `can:` middleware and policies all go through here.
 */
final class Access
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function allows(User $user, string $permission): bool
    {
        if ($this->current->get() === null || $this->current->role() === null) {
            return false;
        }

        // The Admin column is locked to full access (spec §2).
        if ($this->current->isAdmin()) {
            return true;
        }

        if (Catalog::isLocked($permission)) {
            return false;
        }

        return $user->checkPermissionTo($permission);
    }

    /** @return array<string, bool> page key → may open */
    public function pages(User $user): array
    {
        $pages = [];

        foreach (array_keys(Catalog::PAGES) as $page) {
            $pages[$page] = $this->allows($user, Catalog::pagePermission($page));
        }

        return $pages;
    }

    /** @return array<string, bool> action key → may do */
    public function actions(User $user): array
    {
        $actions = [];

        foreach (array_keys(Catalog::ACTIONS) as $action) {
            $actions[$action] = $this->allows($user, Catalog::actionPermission($action));
        }

        return $actions;
    }
}
