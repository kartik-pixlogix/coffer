<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultPolicySetting;
use App\Services\Vault\PwnedPasswords;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultPolicy;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vault policies (VaultLockPolicies.dc.html "Policies", spec §14.4, D76), per company and
 * Admin only. Saving writes one row, an audit event naming each changed setting with its
 * old and new value, and an activity-log entry. VaultSession and the reveal responses read
 * them through VaultPolicy::for().
 */
final class VaultPoliciesController extends Controller
{
    public function index(Request $request, VaultSession $session, VaultAccess $access, CurrentCompany $current): Response
    {
        $user = $this->admin($request, $access);
        $company = $current->require();

        return Inertia::render('vault/policies', [
            'vaultState' => $session->pageState($user),
            'policy' => VaultPolicy::for($company)->toForm(),
            'options' => [
                'reauth' => VaultPolicy::REAUTH,
                'idle' => VaultPolicy::IDLE,
                'clipboard' => VaultPolicy::CLIPBOARD,
                'reveal' => VaultPolicy::REVEAL,
                'generator' => VaultPolicy::GENERATOR,
            ],
            'vaultCount' => Vault::query()->count(),
            'breachAvailable' => PwnedPasswords::enabled(),
        ]);
    }

    public function update(Request $request, VaultAccess $access, VaultAudit $audit, CurrentCompany $current): RedirectResponse
    {
        $user = $this->admin($request, $access);
        $company = $current->require();

        /** @var array{reauth_minutes: int, idle_lock_minutes: int, fresh_code_each_reveal: bool, lock_on_close: bool, clipboard_clear_seconds: int, reveal_seconds: int, export_who: string, export_reason: bool, breach_checks: bool, generator_min_length: int} $data */
        $data = $request->validate([
            'reauth_minutes' => ['required', 'integer', Rule::in(VaultPolicy::REAUTH)],
            'idle_lock_minutes' => ['required', 'integer', Rule::in(VaultPolicy::IDLE)],
            'fresh_code_each_reveal' => ['required', 'boolean'],
            'lock_on_close' => ['required', 'boolean'],
            'clipboard_clear_seconds' => ['required', 'integer', Rule::in(VaultPolicy::CLIPBOARD)],
            'reveal_seconds' => ['required', 'integer', Rule::in(VaultPolicy::REVEAL)],
            'export_who' => ['required', Rule::in(VaultPolicy::EXPORT_WHO)],
            'export_reason' => ['required', 'boolean'],
            'breach_checks' => ['required', 'boolean'],
            'generator_min_length' => ['required', 'integer', Rule::in(VaultPolicy::GENERATOR)],
        ]);

        $before = VaultPolicy::for($company)->toForm();
        $after = VaultPolicy::fromArray([
            'reauth_minutes' => (int) $data['reauth_minutes'],
            'idle_lock_minutes' => (int) $data['idle_lock_minutes'],
            'fresh_code_each_reveal' => (bool) $data['fresh_code_each_reveal'],
            'lock_on_close' => (bool) $data['lock_on_close'],
            'clipboard_clear_seconds' => (int) $data['clipboard_clear_seconds'],
            'reveal_seconds' => (int) $data['reveal_seconds'],
            'export_who' => (string) $data['export_who'],
            'export_reason' => (bool) $data['export_reason'],
            'breach_checks' => (bool) $data['breach_checks'],
            'generator_min_length' => (int) $data['generator_min_length'],
        ])->toForm();
        $changed = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changed[$key] = self::show($before[$key]).' → '.self::show($value);
            }
        }

        if ($changed === []) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Nothing changed']);

            return back();
        }

        VaultPolicySetting::query()->updateOrCreate(['company_id' => $company->id], [...$after, 'updated_by' => $user->id]);
        $audit->record(VaultAuditAction::PoliciesChanged, $user, null, null, $changed, $company->id);
        activity('vault')->performedOn($company)->causedBy($user)->withProperties(['changed' => $changed])->log('Vault policies changed');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vault policies saved. They apply on next unlock.']);

        return back();
    }

    private static function show(int|bool|string $value): string
    {
        return is_bool($value) ? ($value ? 'on' : 'off') : (string) $value;
    }

    private function admin(Request $request, VaultAccess $access): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $access->isAdmin($user), 403);

        return $user;
    }
}
