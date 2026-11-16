<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\VaultItemRequest;
use App\Models\User;
use App\Models\VaultAttachment;
use App\Models\VaultItem;
use App\Models\VaultSecret;
use App\Services\Vault\ItemAttachments;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\Totp;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultCrypto;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only ways a secret leaves the server (spec §14.3): one field per request, JSON,
 * `no-store`, after Use access, an unlocked vault and a 2FA check newer than the policy
 * window; rate-limited per user (`throttle:vault-reveal`) and audited every time.
 */
final class VaultSecretController extends Controller
{
    private const array NO_STORE = ['Cache-Control' => 'no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly VaultSession $session, private readonly VaultAudit $audit) {}

    public function reveal(Request $request, VaultItem $item, ItemSecrets $secrets): JsonResponse
    {
        return $this->field($request, $item, $secrets, VaultAuditAction::Revealed);
    }

    public function copy(Request $request, VaultItem $item, ItemSecrets $secrets): JsonResponse
    {
        return $this->field($request, $item, $secrets, VaultAuditAction::Copied);
    }

    /** The current one-time code and seconds left; the setup key never leaves (audited "used one-time code"). */
    public function totp(Request $request, VaultItem $item, ItemSecrets $secrets): JsonResponse
    {
        if (($blocked = $this->guard($request, $item)) !== null) {
            return $blocked;
        }

        $key = $item->has_totp ? $secrets->get($item, 'totp') : null;

        if ($key === null) {
            return response()->json(['message' => 'This item has no 2FA setup key.'], 404);
        }

        $code = Totp::now($key);
        $this->audit->record(VaultAuditAction::UsedCode, $this->user($request), $item, 'totp', ['label' => 'One-time code']);
        $this->session->touch();

        return response()->json($code, 200, self::NO_STORE);
    }

    /** An attachment, decrypted (spec §14.1 attachments 🔒). */
    public function download(Request $request, VaultAttachment $attachment, ItemAttachments $attachments): Response
    {
        $item = $attachment->item;
        abort_if($item->trashed(), 404);

        if (($blocked = $this->guard($request, $item)) !== null) {
            return $blocked;
        }

        $bytes = $attachments->read($attachment);
        $this->audit->record(VaultAuditAction::Downloaded, $this->user($request), $item, null, ['file' => $attachment->name]);
        $this->session->touch();

        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $attachment->name) ?: 'attachment';

        return response($bytes, 200, [
            ...self::NO_STORE,
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($attachment->name),
        ]);
    }

    /**
     * "Used elsewhere" in the add form's security check (VaultAddLogin.dc.html): compares the
     * typed password's HMAC fingerprint with the items the user can Use, never plaintext.
     */
    public function reuse(Request $request, CurrentCompany $current, VaultCrypto $crypto, VaultAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'password' => ['required', 'string', 'max:'.VaultItemRequest::MAX_SECRET],
            'item_id' => ['nullable', 'integer'],
        ]);

        abort_unless($user->hasTwoFactorEnabled(), 403);

        if (! $this->session->isUnlocked()) {
            return response()->json(['message' => 'The vault is locked.', 'locked' => true], 423);
        }

        $usable = $access->visibleItems($user)
            ->filter(fn (VaultItem $item) => $access->can($user, $item, VaultAccessLevel::Use))
            ->keyBy('id');

        $fingerprint = $crypto->fingerprint($current->require()->id, (string) $data['password']);
        $ids = VaultSecret::query()->where('fingerprint', $fingerprint)
            ->whereIn('vault_item_id', $usable->keys())
            ->when($data['item_id'] ?? null, fn ($q, $id) => $q->where('vault_item_id', '!=', (int) $id))
            ->pluck('vault_item_id')->unique();

        return response()->json([
            'matches' => $ids->map(fn (int $id) => ['id' => $id, 'name' => $usable[$id]->name])->values(),
            'checked' => $usable->filter(fn (VaultItem $item) => $item->strength !== null)->count(),
        ], 200, self::NO_STORE);
    }

    private function field(Request $request, VaultItem $item, ItemSecrets $secrets, VaultAuditAction $action): JsonResponse
    {
        $request->validate(['field' => ['required', 'string', 'max:64']]);

        if (($blocked = $this->guard($request, $item)) !== null) {
            return $blocked;
        }

        $field = $request->string('field')->toString();
        $label = ItemTemplates::secretLabel($item, $field);

        if ($label === null) {
            return response()->json(['message' => 'That isn’t a secret field of this item.'], 422);
        }

        $value = $secrets->get($item, $field);

        if ($value === null) {
            return response()->json(['message' => "Nothing is saved in {$label}."], 404);
        }

        $this->audit->record($action, $this->user($request), $item, $field, ['label' => $label]);
        $this->session->touch();
        $policy = $this->session->policy();

        return response()->json([
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'hideAfter' => $policy->revealSeconds,
            'clearAfter' => $policy->clipboardClearSeconds,
        ], 200, self::NO_STORE);
    }

    /** Use access, then the lock (423), then the step-up (428). */
    private function guard(Request $request, VaultItem $item): ?JsonResponse
    {
        Gate::forUser($this->user($request))->authorize('reveal', $item);

        if (! $this->session->isUnlocked()) {
            return response()->json(['message' => 'The vault locked. Unlock it to continue.', 'locked' => true], 423);
        }

        if (! $this->session->isVerified()) {
            return response()->json(['message' => 'Enter a code from your authenticator app to continue.', 'stepUp' => true], 428);
        }

        return null;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
