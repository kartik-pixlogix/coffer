<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Company;
use App\Services\Privacy\CompanyEraser;
use Illuminate\Support\Facades\DB;

/**
 * Erases one company for good (V4, D140) once its deletion grace period is over: every row,
 * activity-log entry and file of its own (CompanyEraser). The workspace, its subscription and
 * its other companies stay; people keep their accounts. One transaction.
 */
final readonly class PurgeCompany
{
    public function __construct(private CompanyEraser $eraser) {}

    public function handle(Company $company): void
    {
        $ids = [$company->id];
        $files = [];

        DB::transaction(function () use ($company, $ids, &$files): void {
            $files = $this->eraser->erase($ids);

            activity('privacy')->withProperties(['workspace_id' => $company->workspace_id, 'company_id' => $company->id])->log('Company erased');
        });

        $this->eraser->deleteFiles($files, $ids);
    }
}
