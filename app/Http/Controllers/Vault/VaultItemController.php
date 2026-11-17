<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Actions\Vault\RestoreVaultItemVersion;
use App\Actions\Vault\SaveVaultItem;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Enums\VaultItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\VaultItemRequest;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAttachment;
use App\Models\VaultFavourite;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Models\VaultItemVersion;
use App\Services\Vault\ItemPresenter;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\ProviderCatalog;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultPolicy;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Add item (VaultAddCatalog.dc.html: step 1, the provider catalog; VaultAddLogin.dc.html:
 * step 2, the template form with the generator) and Edit with the same form, plus delete,
 * favourite, version history and restore (spec §14.4). The edit form never receives a
 * secret: it learns which secret fields are set, and an empty field keeps its value.
 */
final class VaultItemController extends Controller
{
    public function __construct(private readonly VaultAccess $access, private readonly VaultSession $session) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->user($request);

        if (($redirect = $this->needsUnlock($user)) !== null) {
            return $redirect;
        }

        $vaults = $this->access->creatableVaults($user);
        abort_if($vaults->isEmpty(), 403, 'You can’t add items to any vault.');

        $provider = ProviderCatalog::find($request->string('provider')->toString() ?: null);
        $type = VaultItemType::tryFrom($request->string('type')->toString()) ?? ($provider === null ? null : VaultItemType::from($provider['type']));
        $vaultId = $request->integer('vault') ?: null;

        return Inertia::render('vault/create', [
            ...$this->formProps($user, $vaults),
            'step' => $type === null ? 'catalog' : 'details',
            'provider' => $provider,
            'type' => $type?->value,
            'item' => null,
            'defaultVaultId' => ($vaultId !== null && $vaults->contains('id', $vaultId)) ? $vaultId : $vaults->first()->id,
            'rotate' => false,
        ]);
    }

    public function store(VaultItemRequest $request, SaveVaultItem $save): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasTwoFactorEnabled(), 403);
        $vault = Vault::query()->findOrFail($request->integer('vault_id'));
        abort_unless($this->access->canCreateIn($user, $vault), 403);

        $item = $save->handle($user, $request->validated(), null, $this->files($request), $this->access->isAdmin($user));

        // Whoever adds an item manages it (spec §2.2 "item Managers"), within their role's ceiling.
        if (! $this->access->isAdmin($user) && $this->access->vaultLevel($user, $vault) !== VaultAccessLevel::Manage) {
            VaultGrant::query()->create(['vault_item_id' => $item->id, 'user_id' => $user->id, 'level' => VaultAccessLevel::Manage, 'granted_by' => $user->id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$item->name} saved to {$vault->name}"]);

        return redirect()->route('vault.index', ['item' => $item->id, ...($request->boolean('share') ? ['share' => 1] : [])]);
    }

    public function edit(Request $request, VaultItem $item, ItemSecrets $secrets): Response|RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('update', $item);

        if (($redirect = $this->needsUnlock($user)) !== null) {
            return $redirect;
        }

        $vaults = $this->access->creatableVaults($user);

        if (! $vaults->contains('id', $item->vault_id)) {
            $vaults->prepend($item->vault);
        }

        $set = $secrets->keys($item);

        return Inertia::render('vault/create', [
            ...$this->formProps($user, $vaults),
            'step' => 'details',
            'provider' => ProviderCatalog::find($item->provider),
            'type' => $item->type->value,
            'item' => [
                'id' => $item->id,
                'vaultId' => $item->vault_id,
                'type' => $item->type->value,
                'provider' => $item->provider,
                'name' => $item->name,
                'environment' => $item->environment?->value,
                'tags' => $item->tags ?? [],
                'expiresOn' => $item->expires_at?->toDateString(),
                'restricted' => $item->restricted,
                'strength' => $item->strength,
                'fields' => (object) ($item->fields ?? []),
                'secretsSet' => array_values(array_filter($set, fn (string $k) => ! str_starts_with($k, 'custom.'))),
                'customFields' => array_map(fn (array $c) => [
                    'id' => $c['id'],
                    'label' => $c['label'],
                    'secret' => $c['secret'],
                    'value' => $c['secret'] ? '' : ($c['value'] ?? ''),
                    'set' => $c['secret'] && in_array(ItemTemplates::customKey($c['id']), $set, true),
                ], $item->custom_fields ?? []),
                'attachments' => $item->attachments()->orderBy('id')->get()
                    ->map(fn (VaultAttachment $a) => ['id' => $a->id, 'name' => $a->name, 'size' => $a->size])->values(),
            ],
            'defaultVaultId' => $item->vault_id,
            'rotate' => $request->boolean('rotate'),
        ]);
    }

    public function update(VaultItemRequest $request, VaultItem $item, SaveVaultItem $save): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('update', $item);

        if ($request->integer('vault_id') !== $item->vault_id) {
            $target = Vault::query()->findOrFail($request->integer('vault_id'));
            abort_unless($this->access->canCreateIn($user, $target), 403, 'You can’t move items into that vault.');
        }

        $item = $save->handle($user, $request->validated(), $item, $this->files($request), $this->access->isAdmin($user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$item->name} saved"]);

        return redirect()->route('vault.index', ['item' => $item->id, ...($request->boolean('share') ? ['share' => 1] : [])]);
    }

    public function destroy(Request $request, VaultItem $item, VaultAudit $audit): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('delete', $item);

        $item->delete();
        $audit->record(VaultAuditAction::Deleted, $user, $item);
        activity('vault')->performedOn($item)->causedBy($user)
            ->withProperties(['vault_id' => $item->vault_id, 'name' => $item->name])->log('Vault item deleted');

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$item->name} deleted"]);

        return redirect()->route('vault.index');
    }

    public function favourite(Request $request, VaultItem $item): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('view', $item);

        $existing = VaultFavourite::query()->where('user_id', $user->id)->where('vault_item_id', $item->id)->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            VaultFavourite::query()->create(['vault_item_id' => $item->id, 'user_id' => $user->id, 'created_at' => now()]);
        }

        return back();
    }

    /** Version history (metadata only): what changed when, and by whom. */
    public function history(Request $request, VaultItem $item, ItemPresenter $presenter): JsonResponse
    {
        Gate::authorize('view', $item);

        return response()->json(['versions' => $presenter->versions($item), 'canRestore' => Gate::allows('restore', $item)]);
    }

    public function restore(Request $request, VaultItem $item, int $version, RestoreVaultItemVersion $restore): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('restore', $item);

        if (($redirect = $this->needsUnlock($user)) !== null) {
            return $redirect;
        }

        $old = VaultItemVersion::query()->where('vault_item_id', $item->id)->where('version', $version)->firstOrFail();

        if ($old->version === $item->version) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'That’s already the current version']);

            return back();
        }

        $restore->handle($user, $item, $old);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Version {$version} restored as version {$item->version}"]);

        return redirect()->route('vault.index', ['item' => $item->id]);
    }

    /**
     * @param  Collection<int, Vault>  $vaults
     * @return array<string, mixed>
     */
    private function formProps(User $user, Collection $vaults): array
    {
        $saved = $this->access->visibleItems($user)->whereNotNull('provider')->countBy('provider');

        return [
            'catalog' => array_map(fn (array $p) => [...$p, 'saved' => (int) ($saved[$p['key']] ?? 0)], ProviderCatalog::all()),
            'categories' => ProviderCatalog::CATEGORIES,
            'types' => array_map(fn (VaultItemType $t) => ['type' => $t->value, 'label' => $t->label()], VaultItemType::cases()),
            'templates' => ItemTemplates::all(),
            'vaults' => $vaults->map(fn (Vault $v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'canRestrict' => $this->access->isAdmin($user),
            'attachmentMaxKb' => (int) config('vault.attachment_max_kb'),
            // The Policies page's shortest generated length (D76).
            'generatorMinLength' => VaultPolicy::for(app(CurrentCompany::class)->get())->generatorMinLength,
        ];
    }

    private function needsUnlock(User $user): ?RedirectResponse
    {
        if ($user->hasTwoFactorEnabled() && $this->session->isUnlocked()) {
            $this->session->touch();

            return null;
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $user->hasTwoFactorEnabled() ? 'Unlock the vault to add or edit items.' : 'Turn on two-factor sign-in to use the vault.']);

        return redirect()->route('vault.index');
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        $files = $request->file('attachments');

        return $files === null ? [] : array_values(is_array($files) ? $files : [$files]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
