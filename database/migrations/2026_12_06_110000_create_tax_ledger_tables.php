<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tax in the ledger (D161). Tax accounts are keyed by `tax_key` (cgst, sgst, igst, a TDS
     * section, or tds). `document_taxes` holds the GST and TDS of one expense or income: the
     * figures the returns are built from (D162). Members get the TDS deducted from their pay.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('tax_key', 16)->nullable()->after('category_id');
        });

        Schema::create('document_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->morphs('taxable');
            $table->string('direction', 8);
            $table->string('supply', 8)->nullable();
            $table->text('party_gstin')->nullable();
            $table->char('party_gstin_index', 64)->nullable();
            $table->char('party_state', 2)->nullable();
            $table->string('invoice_no', 32)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('hsn_sac', 8)->nullable();
            $table->unsignedSmallInteger('rate_bps')->default(0);
            $table->bigInteger('taxable_paise')->default(0);
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->boolean('reverse_charge')->default(false);
            $table->boolean('itc')->default(false);
            $table->string('tds_section', 24)->nullable();
            $table->bigInteger('tds_paise')->default(0);
            $table->text('deductee_pan')->nullable();
            $table->timestamps();

            $table->unique(['taxable_type', 'taxable_id']);
            $table->index(['company_id', 'direction']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('tds_section', 24)->nullable()->after('rate_paise');
            $table->bigInteger('tds_monthly_paise')->default(0)->after('tds_section');
        });

        Schema::table('salary_runs', function (Blueprint $table) {
            $table->bigInteger('tds_paise')->default(0)->after('amount_paise');
        });
    }

    public function down(): void
    {
        Schema::table('salary_runs', function (Blueprint $table) {
            $table->dropColumn('tds_paise');
        });
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['tds_section', 'tds_monthly_paise']);
        });
        Schema::dropIfExists('document_taxes');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('tax_key');
        });
    }
};
