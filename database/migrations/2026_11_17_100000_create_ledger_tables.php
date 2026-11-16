<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The double-entry ledger (spec §4). Money is integer paise in bigint columns.
     */
    public function up(): void
    {
        // Income and expense categories (spec §4.1 4xxx / 5xxx). Salary is a system category.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('kind', 8);
            $table->string('name');
            $table->boolean('system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'kind']);
        });

        // Where cash sits: the company account, one per JV side, and extra bank or cash accounts.
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('side_id')->nullable()->constrained();
            $table->string('name');
            $table->string('kind', 16);
            $table->string('bank_label')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // The chart of accounts (spec §4.1). One row per money account, partner, member or category role.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('code', 16);
            $table->string('name');
            $table->string('type', 16);
            $table->string('role', 32);
            $table->foreignId('money_account_id')->nullable()->constrained();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('member_id')->nullable()->constrained();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'role']);
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label', 16);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->unsignedBigInteger('closing_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'starts_on']);
        });

        // Posted entries are never edited: corrections post a reversal and a new entry (D5).
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->date('date');
            $table->string('type', 24);
            $table->nullableMorphs('source');
            $table->string('memo', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('reverses_id')->nullable()->constrained('journal_entries');
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries');
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            // Copied from the entry so period queries never join.
            $table->date('date');
            $table->bigInteger('debit_paise')->default(0);
            $table->bigInteger('credit_paise')->default(0);
            // Dimensions for attribution and settlements (spec §4.1, §4.4, §13).
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('side_id')->nullable()->constrained();
            $table->foreignId('member_id')->nullable()->constrained();
            // Projects arrive in Phase 4, which adds the foreign key.
            $table->unsignedBigInteger('project_id')->nullable();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('money_accounts');
        Schema::dropIfExists('categories');
    }
};
