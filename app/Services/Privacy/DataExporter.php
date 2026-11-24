<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Security\SealedFields;
use App\Support\Csv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds DPDPA export archives (spec §23, D98).
 *
 * - **Account:** the person's own data as JSON: profile, memberships, sessions and trusted
 *   devices, notifications, what they did (activity log, vault audit trail), and the records
 *   they created (expenses submitted, income recorded, time logged as a team member, vault
 *   items they added, names and addresses only). Never a vault secret, never anyone else's
 *   profile.
 * - **Workspace:** for its owner, every company's tenant tables as CSV (a folder per company),
 *   plus its people and their roles. Vault ciphertext tables are left out (the vault has its own
 *   encrypted export, D77), as are columns that hold tokens, hashes or wrapped keys.
 */
final class DataExporter
{
    /** Columns never written out, whatever the table. */
    private const string HIDDEN_COLUMNS = '/(token|secret|password|ciphertext|wrapped|nonce|recovery|fingerprint)/i';

    public function __construct(private readonly CompanyTables $tables) {}

    /** Writes the user's archive to $zipPath. */
    public function account(User $user, string $zipPath): void
    {
        $zip = $this->open($zipPath);
        $id = $user->id;

        $companies = DB::table('company_user')->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->join('workspaces', 'workspaces.id', '=', 'companies.workspace_id')
            ->where('company_user.user_id', $id)
            ->get(['companies.id', 'companies.name as company', 'workspaces.name as workspace', 'company_user.role', 'company_user.created_at as joined_at', 'company_user.last_active_at']);

        $this->json($zip, 'profile.json', [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'timezone' => $user->timezone,
            'number_format' => $user->number_format->value,
            'theme' => $user->theme?->value,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'notification_preferences' => $user->notification_preferences,
            'created_at' => $user->created_at?->toIso8601String(),
            'workspaces_owned' => Workspace::query()->where('owner_id', $id)->pluck('name')->all(),
        ]);
        $this->json($zip, 'memberships.json', $companies->map(fn (object $c): array => (array) $c)->all());
        $this->json($zip, 'sessions.json', DB::table('sessions')->where('user_id', $id)->get(['ip_address', 'user_agent', 'last_activity'])->all());
        $this->json($zip, 'trusted_devices.json', DB::table('trusted_devices')->where('user_id', $id)->get()->map(fn (object $d): array => $this->visible((array) $d))->all());
        $this->json($zip, 'notifications.json', DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $id)
            ->orderBy('created_at')->get(['type', 'category', 'data', 'read_at', 'created_at'])->all());
        $this->json($zip, 'activity.json', DB::table('activity_log')->where('causer_type', $user->getMorphClass())->where('causer_id', $id)
            ->orderBy('id')->get(['log_name', 'description', 'subject_type', 'subject_id', 'created_at'])->all());

        if ($user->avatar_path !== null) {
            $avatar = storage_path('app/public/'.$user->avatar_path);

            if (is_file($avatar)) {
                $zip->addFile($avatar, 'avatar.'.pathinfo($avatar, PATHINFO_EXTENSION));
            }
        }

        foreach ($companies as $company) {
            $folder = 'records/'.Str::slug((string) $company->company).'-'.$company->id;
            $memberIds = DB::table('members')->where('company_id', $company->id)->where('user_id', $id)->pluck('id');

            $this->json($zip, "{$folder}/expenses_submitted.json", DB::table('expenses')->where('company_id', $company->id)->where('submitted_by', $id)
                ->orderBy('spent_on')->get(['id', 'company_id', 'description', 'amount_paise', 'spent_on', 'status', 'notes', 'reject_reason', 'created_at'])->map(fn (object $r): array => SealedFields::openRow('expenses', $r))->all());
            $this->json($zip, "{$folder}/income_recorded.json", DB::table('incomes')->where('company_id', $company->id)->where('created_by', $id)
                ->orderBy('received_on')->get(['id', 'company_id', 'source', 'amount_paise', 'received_on', 'notes', 'created_at'])->map(fn (object $r): array => SealedFields::openRow('incomes', $r))->all());
            $this->json($zip, "{$folder}/time_entries.json", DB::table('time_entries')->where('company_id', $company->id)->whereIn('member_id', $memberIds)
                ->orderBy('date')->get(['id', 'company_id', 'date', 'hours_centi', 'project_id', 'notes', 'created_at'])->map(fn (object $r): array => SealedFields::openRow('time_entries', $r))->all());
            $this->json($zip, "{$folder}/vault_items_added.json", DB::table('vault_items')->where('company_id', $company->id)->where('created_by', $id)
                ->orderBy('id')->get(['id', 'name', 'type', 'url', 'created_at'])->all());
            $this->json($zip, "{$folder}/vault_activity.json", DB::table('vault_audit_events')->where('company_id', $company->id)->where('user_id', $id)
                ->orderBy('id')->get(['action', 'vault_item_id', 'ip', 'device', 'created_at'])->all());
        }

        $zip->addFromString('README.txt', 'Your Coffer data, exported '.now()->toIso8601String().".\n\n"
            ."profile.json, memberships.json, sessions.json, trusted_devices.json, notifications.json and activity.json are about you.\n"
            ."records/ holds, per company, what you submitted, recorded or logged there. Amounts are in paise (1/100 rupee).\n"
            ."Vault secrets are never included; use the vault's own encrypted export for those.\n");

        $this->close($zip);
    }

    /** Writes the workspace's archive to $zipPath. */
    public function workspace(Workspace $workspace, string $zipPath): void
    {
        $zip = $this->open($zipPath);
        $scoped = array_diff($this->tables->scoped(), CompanyTables::NOT_EXPORTED);
        $companies = DB::table('companies')->where('workspace_id', $workspace->id)->orderBy('id')->get();

        $this->json($zip, 'workspace.json', [
            'name' => $workspace->name,
            'owner' => $workspace->owner->email,
            'created_at' => $workspace->created_at?->toIso8601String(),
            'companies' => $companies->map(fn (object $c): array => $this->visible(SealedFields::openRow('companies', $c)))->all(),
        ]);

        foreach ($companies as $company) {
            $folder = Str::slug((string) $company->name).'-'.$company->id;

            $this->csv($zip, "{$folder}/people.csv", DB::table('company_user')->join('users', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $company->id)->orderBy('users.name')
                ->get(['users.name', 'users.email', 'company_user.role', 'company_user.created_at as joined_at'])->map(fn (object $r): array => (array) $r)->all());

            foreach ($scoped as $table) {
                $query = DB::table($table)->where('company_id', $company->id);
                $rows = (Schema::hasColumn($table, 'id') ? $query->orderBy('id') : $query)->get()
                    ->map(fn (object $r): array => $this->visible(SealedFields::openRow($table, $r)))->all();

                if ($rows !== []) {
                    $this->csv($zip, "{$folder}/{$table}.csv", $rows);
                }
            }
        }

        $zip->addFromString('README.txt', "{$workspace->name}: every company's records, exported ".now()->toIso8601String().".\n\n"
            ."One folder per company, one CSV per table. Amounts are integer paise (1/100 rupee), hours are hundredths.\n"
            ."Vault secrets are not included: export each vault from Vault > Export (encrypted with your passphrase).\n");

        $this->close($zip);
    }

    private function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        return $zip;
    }

    private function close(ZipArchive $zip): void
    {
        if (! $zip->close()) {
            throw new RuntimeException('Could not write the export archive.');
        }
    }

    private function json(ZipArchive $zip, string $name, mixed $data): void
    {
        $zip->addFromString($name, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function csv(ZipArchive $zip, string $name, array $rows): void
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new RuntimeException('Could not buffer the CSV.');
        }

        $first = reset($rows);

        if ($first !== false) {
            Csv::put($out, array_keys($first));

            foreach ($rows as $row) {
                Csv::put($out, array_values($row));
            }
        }

        rewind($out);
        $zip->addFromString($name, (string) stream_get_contents($out));
        fclose($out);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function visible(array $row): array
    {
        return array_filter($row, fn (string $column): bool => preg_match(self::HIDDEN_COLUMNS, $column) !== 1, ARRAY_FILTER_USE_KEY);
    }
}
