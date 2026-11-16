<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The records behind the rest of spec §4.2: capital contributions and drawings (§11),
     * partner loans and their repayments (§12), personal-card reimbursement payouts (§10)
     * and JV settlement transfers (§13). Each one is the source of one posted journal
     * entry; a delete (or an edit, for transfers) reverses it and soft-deletes the row.
     */
    public function up(): void
    {
        Schema::create('capital_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('partner_id')->constrained();
            // contribution | drawing
            $table->string('kind', 20);
            $table->bigInteger('amount_paise');
            $table->date('moved_on');
            $table->foreignId('money_account_id')->constrained();
            $table->string('note', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'moved_on']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            // Shown as L-01, L-02… per company, never reused.
            $table->unsignedInteger('number');
            $table->foreignId('partner_id')->constrained();
            $table->bigInteger('amount_paise');
            $table->date('lent_on');
            // Received into this account; null when converted from a personal-card balance.
            $table->foreignId('money_account_id')->nullable()->constrained();
            $table->boolean('converted')->default(false);
            $table->string('purpose', 200)->nullable();
            $table->bigInteger('instalment_paise')->nullable();
            $table->date('due_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'lent_on']);
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('loan_id')->constrained();
            $table->bigInteger('amount_paise');
            $table->date('paid_on');
            $table->foreignId('money_account_id')->constrained();
            $table->string('note', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'paid_on']);
        });

        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            // Paid to a partner or a member: exactly one is set.
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('member_id')->nullable()->constrained();
            $table->bigInteger('amount_paise');
            $table->date('paid_on');
            $table->foreignId('money_account_id')->constrained();
            $table->string('note', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'paid_on']);
        });

        Schema::create('settlement_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('from_side_id')->constrained('sides');
            $table->foreignId('to_side_id')->constrained('sides');
            $table->bigInteger('amount_paise');
            $table->date('transferred_on');
            $table->string('reference', 100)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'transferred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_transfers');
        Schema::dropIfExists('reimbursements');
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('capital_movements');
    }
};
