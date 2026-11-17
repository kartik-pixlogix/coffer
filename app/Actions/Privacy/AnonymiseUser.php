<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\VaultRequestStatus;
use App\Models\DataExport;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Erases a person (spec §23, D98) while the books stay whole. The `users` row is kept so
 * every journal entry, expense, audit event and activity record still points at the same id,
 * but it now reads "Deleted user" with an address nobody can sign in with. Everything that is
 * only about them goes: sign-in (password, 2FA, sessions, trusted devices, reset tokens),
 * avatar, preferences, notifications, data exports, memberships and roles, vault grants and
 * favourites, and their personal vaults with every secret in them. Company records that name
 * them as a partner or team member are the company's and stay, unlinked from the account.
 */
final readonly class AnonymiseUser
{
    public const string NAME = 'Deleted user';

    public function handle(User $user): void
    {
        $files = [];

        DB::transaction(function () use ($user, &$files): void {
            $id = $user->id;

            // Personal vaults: the items' secrets, versions and attachments go with them (cascade).
            $vaultIds = Vault::query()->withoutGlobalScopes()->where('owner_id', $id)->pluck('id');
            $itemIds = DB::table('vault_items')->whereIn('vault_id', $vaultIds)->pluck('id');
            $files['vault'] = DB::table('vault_attachments')->whereIn('vault_item_id', $itemIds)->pluck('path')->all();
            DB::table('vault_items')->whereIn('id', $itemIds)->delete();
            DB::table('vaults')->whereIn('id', $vaultIds)->delete();

            DB::table('vault_grants')->where('user_id', $id)->delete();
            DB::table('vault_favourites')->where('user_id', $id)->delete();
            DB::table('vault_access_requests')->where('user_id', $id)->where('status', VaultRequestStatus::Pending->value)
                ->update(['status' => VaultRequestStatus::Cancelled->value]);

            DB::table('company_user')->where('user_id', $id)->delete();
            DB::table('model_has_roles')->where('model_type', $user->getMorphClass())->where('model_id', $id)->delete();
            DB::table('model_has_permissions')->where('model_type', $user->getMorphClass())->where('model_id', $id)->delete();
            DB::table('members')->where('user_id', $id)->update(['user_id' => null]);
            DB::table('partners')->where('user_id', $id)->update(['user_id' => null]);
            DB::table('company_drafts')->where('user_id', $id)->delete();

            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('trusted_devices')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $id)->delete();

            $exports = DataExport::query()->where('user_id', $id)->get();
            $files['exports'] = $exports->pluck('path')->filter()->values()->all();
            DataExport::query()->whereKey($exports->modelKeys())->delete();

            $files['avatar'] = array_filter([$user->avatar_path]);

            $user->forceFill([
                'name' => self::NAME,
                'email' => "deleted-user-{$id}@deleted.invalid",
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'password_changed_at' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'theme' => null,
                'avatar_path' => null,
                'notification_preferences' => null,
                'deletion_requested_at' => null,
                'deletion_scheduled_for' => null,
                'anonymised_at' => now(),
            ])->save();

            activity('privacy')->performedOn($user)->log('Account erased');
        });

        Storage::disk((string) config('vault.attachments_disk'))->delete($files['vault'] ?? []);
        Storage::disk((string) config('privacy.disk'))->delete($files['exports'] ?? []);
        Storage::disk('public')->delete($files['avatar'] ?? []);
    }
}
