<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses (spec §10) and who paid them. Only approved expenses post (spec §4.3): the
     * approval posts one journal entry with a pair of lines per payer. Salary runs keep
     * posting their own entry and get a read-only "Auto" expense row beside it (D43).
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('description', 200);
            $table->bigInteger('amount_paise');
            $table->date('spent_on');
            $table->foreignId('category_id')->constrained();
            $table->foreignId('project_id')->nullable()->constrained();
            // "Bill to a client": shows on that project's client summary.
            $table->boolean('billable')->default(false);
            $table->string('payer_mode', 16);
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();
            $table->string('status', 16);
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('auto')->default(false);
            $table->foreignId('salary_run_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'spent_on']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('expense_payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // company | partner | member
            $table->string('payer_type', 16);
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('member_id')->nullable()->constrained();
            // Null: a personal card, to be reimbursed (never for the company).
            $table->foreignId('money_account_id')->nullable()->constrained();
            $table->bigInteger('amount_paise');

            $table->index(['company_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payers');
        Schema::dropIfExists('expenses');
    }
};
