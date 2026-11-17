<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultItem;
use App\Services\Vault\Transfer\ExportCrypto;
use App\Services\Vault\Transfer\VaultExporter;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultPolicy;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Vault › Export (spec §14.4: "an encrypted, password-protected JSON file. Admin only,
 * needs step-up, audited", D77). Who may export is the Policies page's "Who can export";
 * a fresh 2FA code (step-up) is needed, plus a reason when the policy asks. The file is
 * sealed by ExportCrypto with the admin's passphrase, which is never stored or logged.
 */
final class VaultExportController extends Controller
{
    public function index(Request $request, VaultSession $session, VaultExporter $exporter, CurrentCompany $current): Response
    {
        $user = $this->authorizeExport($request, $current);
        $policy = VaultPolicy::for($current->require());
        $items = $session->opensFor($user) ? $exporter->items($user, null)->countBy('vault_id') : collect();

        return Inertia::render('vault/export', [
            'vaultState' => $session->pageState($user),
            'vaults' => Vault::query()->orderBy('position')->orderBy('name')->get()
                ->filter(fn (Vault $v) => ($items[$v->id] ?? 0) > 0)
                ->map(fn (Vault $v) => ['id' => $v->id, 'name' => $v->name, 'items' => (int) ($items[$v->id] ?? 0)])->values(),
            'needsCode' => ! $session->isVerified(),
            'requireReason' => $policy->exportReason,
            'minPassphrase' => ExportCrypto::MIN_PASSPHRASE,
            'kdf' => ExportCrypto::argonAvailable() ? 'argon2id' : 'pbkdf2-sha256',
        ]);
    }

    public function store(Request $request, VaultSession $session, VaultExporter $exporter, ExportCrypto $crypto, VaultAudit $audit, CurrentCompany $current, TwoFactorAuthenticationProvider $twoFactor): HttpResponse
    {
        $user = $this->authorizeExport($request, $current);
        $company = $current->require();
        $policy = VaultPolicy::for($company);

        $data = $request->validate([
            'passphrase' => ['required', 'string', 'min:'.ExportCrypto::MIN_PASSPHRASE, 'max:1024', 'confirmed'],
            'reason' => [$policy->exportReason ? 'required' : 'nullable', 'string', 'max:200'],
            'vault_ids' => ['nullable', 'array'],
            'vault_ids.*' => ['integer'],
            'code' => ['nullable', 'string', 'max:16'],
        ], [
            'passphrase.min' => 'Use at least '.ExportCrypto::MIN_PASSPHRASE.' characters. A few words work well.',
            'passphrase.confirmed' => 'The two passphrases don’t match.',
            'reason.required' => 'Say why you’re exporting. It goes in the audit log.',
        ]);

        // Step-up (spec §14.3): a 2FA code within the policy window, or one entered here.
        if (! $session->isVerified()) {
            $code = preg_replace('/\s+/', '', (string) ($data['code'] ?? '')) ?? '';

            if (preg_match('/^\d{6}$/', $code) !== 1 || ! $twoFactor->verify((string) decrypt((string) $user->two_factor_secret), $code)) {
                throw ValidationException::withMessages(['code' => 'Enter the current 6-digit code from your authenticator app.']);
            }

            $session->unlock(withCode: true);
        }

        $vaultIds = isset($data['vault_ids']) && $data['vault_ids'] !== [] ? array_values(array_map('intval', $data['vault_ids'])) : null;
        $items = $exporter->items($user, $vaultIds);

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['vault_ids' => 'There’s nothing you can export in those vaults.']);
        }

        $file = $crypto->seal($exporter->payload($items), (string) $data['passphrase'], ['company' => $company->name, 'items' => $items->count()]);
        $session->spendStepUp();
        $name = 'coffer-vault-'.str($company->name)->slug().'-'.now()->format('Y-m-d').'.coffer.json';

        $audit->record(VaultAuditAction::Exported, $user, null, null, [
            'what' => 'vault export',
            'items' => $items->count(),
            'vaults' => $vaultIds === null ? 'all' : implode(', ', $items->map(fn (VaultItem $i) => $i->vault->name)->unique()->values()->all()),
            'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 200) : null,
            'file' => $name,
        ]);
        activity('vault')->performedOn($company)->causedBy($user)->withProperties(['items' => $items->count(), 'file' => $name])->log('Vault exported');

        return response($file, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function authorizeExport(Request $request, CurrentCompany $current): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && app(VaultAccess::class)->ceiling($user) !== null, 403);
        abort_unless(VaultPolicy::for($current->require())->allowsExport($current->role()), 403);

        return $user;
    }
}
