<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Income records (spec §9). Each one is the source of one posted journal entry; an
     * edit reverses it and posts a new one, a delete reverses it and soft-deletes the row.
     */
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('source', 200);
            $table->bigInteger('amount_paise');
            $table->date('received_on');
            $table->foreignId('category_id')->constrained();
            // Optional in JV-pooled attribution; per-partner sharing needs it (spec §4.4).
            $table->foreignId('brought_in_by')->nullable()->constrained('partners');
            // Received into a money account, or by a partner personally: exactly one is set.
            $table->foreignId('money_account_id')->nullable()->constrained();
            $table->foreignId('received_by')->nullable()->constrained('partners');
            $table->foreignId('project_id')->nullable()->constrained();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
