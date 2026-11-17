<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Company;
use App\Models\Workspace;
use App\Services\Privacy\CompanyTables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;

/**
 * Erases a workspace for good (spec §23, D98), after its grace period: every row of its
 * companies in every tenant table (found from the schema, CompanyTables), their activity-log
 * entries, uploaded files, the companies, drafts, subscription rows and impersonation records,
 * and the workspace. People keep their accounts (and other workspaces); the ones who belonged
 * only here land on "create a company" next time they sign in. One transaction: it all goes or
 * nothing does. Deletion order follows the foreign keys by retrying, so no list needs upkeep.
 */
final readonly class PurgeWorkspace
{
    public function __construct(private CompanyTables $tables) {}

    public function handle(Workspace $workspace): void
    {
        $companyIds = array_values(DB::table('companies')->where('workspace_id', $workspace->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $files = [];

        DB::transaction(function () use ($workspace, $companyIds, &$files): void {
            $scoped = $this->tables->scoped();

            if ($companyIds !== []) {
                $files = $this->filePaths($scoped, $companyIds);
                $this->forgetActivity($scoped, $companyIds);

                foreach ($this->tables->children() as [$table, $column, $parent]) {
                    DB::table($table)->whereIn($column, DB::table($parent)->select('id')->whereIn('company_id', $companyIds))->delete();
                }

                // Self-references (a reversal pointing at its entry) would block a one-statement delete.
                foreach ($scoped as $table) {
                    foreach ($this->tables->foreignKeys($table) as $fk) {
                        if ($fk['foreign_table'] === $table && count($fk['columns']) === 1) {
                            DB::table($table)->whereIn('company_id', $companyIds)->update([$fk['columns'][0] => null]);
                        }
                    }
                }

                $this->deleteInDependencyOrder($scoped, $companyIds);

                DB::table('activity_log')->where('subject_type', (new Company)->getMorphClass())->whereIn('subject_id', $companyIds)->delete();
                DB::table('companies')->whereIn('id', $companyIds)->delete();
            }

            DB::table('company_drafts')->where('workspace_id', $workspace->id)->delete();
            DB::table('subscription_items')->whereIn('subscription_id', DB::table('subscriptions')->select('id')->where('workspace_id', $workspace->id))->delete();
            DB::table('subscriptions')->where('workspace_id', $workspace->id)->delete();
            DB::table('impersonations')->where('workspace_id', $workspace->id)->delete();
            DB::table('data_exports')->where('workspace_id', $workspace->id)->delete();

            activity('privacy')->withProperties(['workspace_id' => $workspace->id, 'companies' => count($companyIds)])->log('Workspace erased');
            DB::table('activity_log')->where('subject_type', $workspace->getMorphClass())->where('subject_id', $workspace->id)->delete();
            DB::table('workspaces')->where('id', $workspace->id)->delete();
        });

        $local = Storage::disk('local');
        $local->delete($files);
        Storage::disk((string) config('vault.attachments_disk'))->delete($files);

        foreach ($companyIds as $id) {
            $local->deleteDirectory("companies/{$id}");
            Storage::disk('public')->deleteDirectory("companies/{$id}");
        }
    }

    /**
     * @param  list<string>  $scoped
     * @param  list<int>  $companyIds
     */
    private function deleteInDependencyOrder(array $scoped, array $companyIds): void
    {
        $remaining = $scoped;

        for ($pass = 0; $remaining !== [] && $pass <= count($scoped); $pass++) {
            foreach ($remaining as $i => $table) {
                try {
                    // A savepoint, so a refused delete doesn't spoil the outer transaction (PostgreSQL).
                    DB::transaction(fn () => DB::table($table)->whereIn('company_id', $companyIds)->delete());
                    unset($remaining[$i]);
                } catch (QueryException) {
                    // Something still references these rows; a later pass gets it.
                }
            }
        }

        if ($remaining !== []) {
            throw new RuntimeException('Could not erase '.implode(', ', $remaining).': rows outside the workspace still reference them.');
        }
    }

    /**
     * Uploaded files the rows point at (receipts, attachments, logos, imports, exports).
     *
     * @param  list<string>  $scoped
     * @param  list<int>  $companyIds
     * @return list<string>
     */
    private function filePaths(array $scoped, array $companyIds): array
    {
        $paths = [];

        foreach ([...$scoped, 'companies'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if ($column === 'path' || str_ends_with($column, '_path')) {
                    $key = $table === 'companies' ? 'id' : 'company_id';
                    $paths = [...$paths, ...DB::table($table)->whereIn($key, $companyIds)->whereNotNull($column)->pluck($column)->map(fn ($p): string => (string) $p)->all()];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Activity-log entries about the workspace's records (subject = one of its rows).
     *
     * @param  list<string>  $scoped
     * @param  list<int>  $companyIds
     */
    private function forgetActivity(array $scoped, array $companyIds): void
    {
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;

            if (in_array($model->getTable(), $scoped, true) && Schema::hasColumn($model->getTable(), 'id')) {
                DB::table('activity_log')->where('subject_type', $model->getMorphClass())
                    ->whereIn('subject_id', DB::table($model->getTable())->select('id')->whereIn('company_id', $companyIds))
                    ->delete();
            }
        }
    }
}
