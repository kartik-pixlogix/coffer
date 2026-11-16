<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Actions\Vault\DecideVaultAccessRequest;
use App\Actions\Vault\RequestVaultAccess;
use App\Enums\VaultAccessDuration;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessRequest;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultSession;
use App\Services\Vault\VaultSharing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Access requests (VaultShare.dc.html, spec §14.2, D72): the inbox of requests the viewer
 * can decide (Pending, Approved, Denied) and "Recently shared"; asking for access from the
 * item drawer or the vault rail; approving (which writes the grant) or denying.
 */
final class VaultRequestsController extends Controller
{
    public function __construct(private readonly VaultSharing $sharing, private readonly VaultAccess $access) {}

    public function index(Request $request, VaultSession $session): Response
    {
        $user = $this->user($request);
        $open = $session->opensFor($user);

        return Inertia::render('vault/requests', [
            'vaultState' => $session->pageState($user),
            'inbox' => $open ? $this->inbox($user) : null,
            'tab' => in_array($request->query('tab'), ['approved', 'denied'], true) ? $request->query('tab') : 'pending',
        ]);
    }

    public function store(Request $request, RequestVaultAccess $action): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasTwoFactorEnabled(), 403);

        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'required_without:vault_id'],
            'vault_id' => ['nullable', 'integer', 'required_without:item_id'],
            'level' => ['required', Rule::enum(VaultAccessLevel::class)],
            'duration' => ['required', Rule::enum(VaultAccessDuration::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Say why you need it.',
            'reason.min' => 'Say a little more about why you need it.',
            'item_id.required_without' => 'Pick what you need.',
            'vault_id.required_without' => 'Pick a vault.',
        ]);

        $level = VaultAccessLevel::from($data['level']);
        $ceiling = $this->access->ceiling($user);
        abort_if($ceiling === null, 403);

        if (isset($data['item_id'])) {
            $target = VaultItem::query()->findOrFail((int) $data['item_id']);
            $current = $this->access->itemLevel($user, $target);
            abort_if($current === null, 404);
        } else {
            $target = Vault::query()->findOrFail((int) $data['vault_id']);
            abort_if($target->isPersonal(), 404);
            $current = $this->access->vaultLevel($user, $target);
        }

        if ($level->rank() > $ceiling->rank()) {
            throw ValidationException::withMessages(['level' => "Your role allows at most {$ceiling->label()}."]);
        }

        if ($current !== null && $current->atLeast($level)) {
            throw ValidationException::withMessages(['level' => "You already have {$current->label()} access."]);
        }

        $column = $target instanceof VaultItem ? 'vault_item_id' : 'vault_id';

        if (VaultAccessRequest::query()->where('user_id', $user->id)->where($column, $target->id)->where('status', VaultRequestStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages(['reason' => 'You’ve already asked. A Manager will get back to you.']);
        }

        $action->handle($user, $target, $level, VaultAccessDuration::from($data['duration']), $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request sent. You’ll get a notification when it’s decided.']);

        return back();
    }

    public function approve(Request $request, VaultAccessRequest $accessRequest, DecideVaultAccessRequest $decide): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorizeDecision($user, $accessRequest);
        $decide->approve($user, $accessRequest);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$accessRequest->user->name} can {$accessRequest->level->value} {$accessRequest->targetName()}"]);

        return back();
    }

    public function deny(Request $request, VaultAccessRequest $accessRequest, DecideVaultAccessRequest $decide): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorizeDecision($user, $accessRequest);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $decide->deny($user, $accessRequest, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Request from {$accessRequest->user->name} denied"]);

        return back();
    }

    /** Withdraw your own pending request. */
    public function destroy(Request $request, VaultAccessRequest $accessRequest): RedirectResponse
    {
        abort_unless($accessRequest->user_id === $this->user($request)->id, 404);
        abort_unless($accessRequest->status === VaultRequestStatus::Pending, 409);
        $accessRequest->update(['status' => VaultRequestStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request withdrawn']);

        return back();
    }

    private function authorizeDecision(User $user, VaultAccessRequest $accessRequest): void
    {
        abort_unless($this->sharing->canDecide($user, $accessRequest), 403);
        abort_unless($accessRequest->status === VaultRequestStatus::Pending, 409, 'This request was already decided.');
    }

    /** @return array<string, mixed> */
    private function inbox(User $user): array
    {
        $all = VaultAccessRequest::query()
            ->with(['user:id,name', 'decider:id,name', 'vault:id,name,type', 'item' => fn ($q) => $q->withTrashed()->with('vault:id,name,type')])
            ->whereIn('status', [VaultRequestStatus::Pending->value, VaultRequestStatus::Approved->value, VaultRequestStatus::Denied->value])
            ->latest('id')->limit(300)->get();

        $decidable = $all->filter(fn (VaultAccessRequest $r) => $r->user_id !== $user->id && $this->sharing->canDecide($user, $r))->values();
        $mine = $all->where('user_id', $user->id)->take(20)->values();

        $present = fn (VaultAccessRequest $r) => [
            'id' => $r->id,
            'name' => $r->user->name,
            'level' => $r->level->value,
            'target' => $r->item->name ?? $r->vault->name ?? '',
            'targetKind' => $r->vault_item_id !== null ? 'item' : 'vault',
            'itemId' => $r->vault_item_id,
            'vault' => $r->item->vault->name ?? $r->vault->name ?? '',
            'reason' => $r->reason,
            'span' => $r->duration->span(),
            'status' => $r->status->value,
            'createdAt' => $r->created_at?->toIso8601String(),
            'decidedBy' => $r->decider?->name,
            'decidedAt' => $r->decided_at?->toIso8601String(),
            'note' => $r->decision_note,
        ];

        return [
            'requests' => $decidable->map($present)->values()->all(),
            'mine' => $mine->map($present)->values()->all(),
            'recent' => $this->recentlyShared($user),
            'managesAnything' => $this->sharing->managesAnything($user),
        ];
    }

    /** @return list<array{id: int, target: string, who: string, level: string, at: string|null}> */
    private function recentlyShared(User $user): array
    {
        $out = [];
        $grants = VaultGrant::query()->active()->with(['user:id,name', 'vault', 'item.vault'])->latest('updated_at')->limit(60)->get();

        foreach ($grants as $grant) {
            $target = $grant->vault_item_id !== null ? $grant->item : $grant->vault;

            if ($target === null || ! $this->sharing->canShare($user, $target)) {
                continue;
            }

            $out[] = [
                'id' => $grant->id,
                'target' => $target instanceof VaultItem ? $target->name : "{$target->name} vault",
                'who' => $grant->user->name,
                'level' => $grant->level->value,
                'at' => $grant->updated_at?->toIso8601String(),
            ];

            if (count($out) === 6) {
                break;
            }
        }

        return $out;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        \assert($user instanceof User);

        return $user;
    }
}
