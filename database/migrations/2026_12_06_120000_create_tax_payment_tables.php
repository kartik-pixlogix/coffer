<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GST paid for a period after set-off, and TDS deposited by challan (D163). Each posts a
     * journal entry; the row keeps the figures the returns and the TDS summary show.
     */
    public function up(): void
    {
        Schema::create('gst_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->date('period');
            $table->date('paid_on');
            $table->foreignId('money_account_id')->constrained();
            $table->string('reference', 32)->nullable();
            $table->text('breakdown');
            $table->bigInteger('cash_paise');
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'period']);
        });

        Schema::create('tds_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('section', 8);
            $table->date('period');
            $table->bigInteger('amount_paise');
            $table->date('paid_on');
            $table->string('bsr_code', 7)->nullable();
            $table->string('challan_no', 8)->nullable();
            $table->foreignId('money_account_id')->constrained();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_challans');
        Schema::dropIfExists('gst_payments');
    }
};
