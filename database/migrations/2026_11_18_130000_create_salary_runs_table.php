<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salary runs (spec §8.3): one row per member per month, the source of that month's
     * Salary journal entry. A re-run with a different amount reverses the entry and
     * posts a new one (D38).
     */
    public function up(): void
    {
        Schema::create('salary_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('member_id')->constrained();
            $table->date('month');
            $table->unsignedInteger('billable_centi')->default(0);
            $table->bigInteger('amount_paise')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'month']);
            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_runs');
    }
};
