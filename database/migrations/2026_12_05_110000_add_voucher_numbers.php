<?php

use App\Ledger\LedgerGuard;
use App\Ledger\Vouchers;
use App\Models\Company;
use App\Services\Audit\AuditTrail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voucher numbers (D154): every journal entry gets `INC/2026-27/0001`, gapless per company,
     * fiscal year and type. Existing entries are numbered in the order they were posted. The
     * ledger guard lets the voucher be filled in once (null → value) and never changed.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('voucher_no', 32)->nullable()->after('type');
            $table->unsignedInteger('voucher_seq')->nullable()->after('voucher_no');
            $table->unique(['company_id', 'voucher_no']);
        });

        Schema::create('voucher_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('fy_label', 16);
            $table->string('type', 24);
            $table->unsignedInteger('last')->default(0);

            $table->unique(['company_id', 'fy_label', 'type']);
        });

        try {
            LedgerGuard::install();
        } catch (QueryException $e) {
            Log::warning('Ledger guard triggers could not be reinstalled after adding voucher numbers.', ['code' => $e->getCode()]);
        }

        $trail = app(AuditTrail::class);

        $vouchers = app(Vouchers::class);

        foreach (Company::query()->withTrashed()->orderBy('id')->get() as $company) {
            $numbered = $vouchers->backfill($company);

            if ($numbered > 0) {
                $trail->record($company->id, 'vouchers numbered', label: 'Voucher numbers', changes: ['entries' => $numbered]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_sequences');

        LedgerGuard::unlocked(function (): void {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropUnique(['company_id', 'voucher_no']);
                $table->dropColumn(['voucher_no', 'voucher_seq']);
            });
        });

        LedgerGuard::install();
    }
};
