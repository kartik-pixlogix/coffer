<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Companies\SeedCompanyPermissions;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditTrail;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings › Roles & permissions (SettingsMembers.dc.html "Roles" tab, spec §2, D24):
 * the editable page and action matrices. The Admin column is locked to full access.
 * Only Admins change the matrix, so nobody can grant themselves more.
 */
final class RolesController extends Controller
{
    public function index(CurrentCompany $current, SeedCompanyPermissions $seed): Response
    {
        $company = $current->require();
        $matrix = [];

        foreach (Catalog::EDITABLE_ROLES as $role) {
            $matrix[$role->value] = $seed->roleFor($company, $role)->permissions()->pluck('name')->all();
        }

        return Inertia::render('settings/roles', [
            'pages' => collect(Catalog::PAGES)->map(fn (array $page, string $key) => [
                'key' => Catalog::pagePermission($key),
                'label' => $page['label'],
                'note' => $page['note'] ?? null,
                'locked' => $page['locked'] ?? false,
            ])->values(),
            'actions' => collect(Catalog::ACTIONS)->map(fn (array $action, string $key) => [
                'key' => Catalog::actionPermission($key),
                'label' => $action['label'],
                'group' => $action['group'],
                'note' => $action['note'] ?? null,
                'locked' => false,
            ])->values(),
            'matrix' => $matrix,
            'canEdit' => $current->isAdmin(),
        ]);
    }

    public function update(Request $request, CurrentCompany $current, SeedCompanyPermissions $seed, PermissionRegistrar $registrar, AuditTrail $audit): RedirectResponse
    {
        abort_unless($current->isAdmin(), 403);

        $company = $current->require();
        $validated = $request->validate([
            'matrix' => ['required', 'array'],
            'matrix.partner' => ['present', 'array'],
            'matrix.member' => ['present', 'array'],
            'matrix.viewer' => ['present', 'array'],
            'matrix.*.*' => ['string', 'in:'.implode(',', Catalog::all())],
        ]);

        $before = [];
        $changes = [];

        foreach (Catalog::EDITABLE_ROLES as $role) {
            $granted = array_values(array_filter(
                array_unique($validated['matrix'][$role->value]),
                fn (string $permission) => ! Catalog::isLocked($permission),
            ));

            $spatieRole = $seed->roleFor($company, $role);
            $before[$role->value] = $spatieRole->permissions()->pluck('name')->all();
            $spatieRole->syncPermissions($granted);

            $from = $before[$role->value];
            sort($from);
            sort($granted);

            if ($from !== $granted) {
                $changes[$role->value] = ['from' => $from, 'to' => $granted];
            }
        }

        if ($changes !== []) {
            $audit->record($company->id, 'permissions changed', $company, $changes, 'Roles & permissions');
        }

        $registrar->forgetCachedPermissions();

        activity('permissions')->performedOn($company)->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $validated['matrix']])
            ->log('changed the permission matrix');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Permissions saved']);

        return back();
    }

    /** "Reset to defaults" (spec §16): the spec §2.1 / §2.2 matrix again. */
    public function reset(Request $request, CurrentCompany $current, SeedCompanyPermissions $seed, AuditTrail $audit): RedirectResponse
    {
        abort_unless($current->isAdmin(), 403);

        $company = $current->require();
        $seed->handle($company);
        $audit->record($company->id, 'permissions reset', $company, label: 'Roles & permissions');

        activity('permissions')->performedOn($company)->causedBy($request->user())->log('reset the permission matrix');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Permissions are back to the defaults']);

        return back();
    }
}
