<?php

use App\Ledger\LedgerGuard;
use App\Services\Audit\AuditTrail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail (D153, Companies (Accounts) Rules 3(1)): one append-only, hash-chained
     * list of events per company. `audit_heads` holds each chain's last position and hash, and
     * is locked for every append so a company's chain is strictly serial. `changes` is text,
     * not json: the hash covers its exact bytes, which a json column would reformat.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedBigInteger('seq');
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('actor', 16);
            $table->string('impersonator', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('action', 32);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->string('voucher_no', 32)->nullable();
            $table->longText('changes');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['company_id', 'seq']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_heads', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained();
            $table->unsignedBigInteger('seq');
            $table->char('hash', 64);
        });

        // Every company's chain starts with an event that says so.
        $trail = app(AuditTrail::class);

        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $trail->record((int) $companyId, 'started', label: 'Audit trail started');
        }

        try {
            LedgerGuard::install();
        } catch (QueryException $e) {
            Log::warning('Audit trail triggers could not be created; run `php artisan coffer:ledger-guard --install` with a user that may create triggers.', ['code' => $e->getCode()]);
            defined('STDERR') && fwrite(STDERR, "  ! Audit trail triggers not created (database refused). See docs/DEPLOY.md, \"Ledger guard\".\n");
        }
    }

    public function down(): void
    {
        LedgerGuard::unlocked(function (): void {
            Schema::dropIfExists('audit_heads');
            Schema::dropIfExists('audit_events');
        });

        LedgerGuard::install();
    }
};
