<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;

/**
 * Erases companies for good (D98, V4/D140): every row of theirs in every tenant table (found
 * from the schema, CompanyTables), their activity-log entries, the company rows themselves,
 * and afterwards their uploaded files. Used by a workspace's erasure and by one company's.
 * `erase()` runs inside the caller's transaction; `deleteFiles()` once it has committed.
 */
final readonly class CompanyEraser
{
    public function __construct(private CompanyTables $tables) {}

    /**
     * @param  list<int>  $companyIds
     * @return list<string> the files to delete once the transaction has committed
     */
    public function erase(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $scoped = $this->tables->scoped();
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

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @param  list<int>  $companyIds
     */
    public function deleteFiles(array $files, array $companyIds): void
    {
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
            throw new RuntimeException('Could not erase '.implode(', ', $remaining).': rows outside the company still reference them.');
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
     * Activity-log entries about the companies' records (subject = one of their rows).
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
