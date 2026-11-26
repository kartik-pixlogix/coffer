<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's tax profile (D159): GST registration type, state code, TAN (sealed, so a
     * text column for the ciphertext) and who files the returns. The GSTIN already exists.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('gst_registration', 16)->default('unregistered')->after('gstin');
            $table->char('state_code', 2)->nullable()->after('gst_registration');
            $table->text('tan')->nullable()->after('state_code');
            $table->string('gst_filed_by', 16)->default('in_house')->after('tan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['gst_registration', 'state_code', 'tan', 'gst_filed_by']);
        });
    }
};
