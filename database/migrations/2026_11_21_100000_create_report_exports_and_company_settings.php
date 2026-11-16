<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Queued report exports (spec §15, D61): the file lands on the private disk.
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('report', 32);
            $table->string('format', 8);
            $table->json('params');
            $table->string('label');
            $table->string('filename');
            $table->string('status', 16);
            $table->string('path')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
        });

        // Settings › Company (spec §3.2, D63).
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('hours_per_day_centi')->default(800)->after('working_days');
            $table->string('logo_path')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['hours_per_day_centi', 'logo_path']);
        });

        Schema::dropIfExists('report_exports');
    }
};
