<?php

use App\Services\Finance\Attachments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Receipts and invoices stored before D149 are sealed with their company's data key: each
     * gets a new `.v{n}.cfe` file, the row points at it, and the plaintext file is deleted. A
     * file missing from the disk is left alone (and logged): there is nothing to seal.
     */
    public function up(): void
    {
        $files = app(Attachments::class);

        foreach (Attachments::COLUMNS as $table => $column) {
            DB::table($table)->whereNotNull($column)->where($column, 'not like', '%.cfe')->orderBy('id')
                ->chunkById(100, function ($rows) use ($files, $table, $column): void {
                    foreach ($rows as $row) {
                        $old = (string) $row->{$column};

                        try {
                            $new = $files->reseal((int) $row->company_id, $old);
                        } catch (RuntimeException $e) {
                            Log::warning('A finance file could not be sealed', ['table' => $table, 'id' => $row->id, 'reason' => $e->getMessage()]);

                            continue;
                        }

                        if ($new !== null) {
                            DB::table($table)->where('id', $row->id)->update([$column => $new]);
                            $files->delete($old);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Sealed files stay sealed: Attachments reads both kinds.
    }
};
