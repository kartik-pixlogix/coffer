<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects (spec §16): a per-company list used by timesheets, income and expenses.
     * Also the company's working days (spec §3.2), which month-mode timesheets spread
     * hours over: a bitmask, Monday = 1 … Sunday = 64; Mon–Fri by default.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 120);
            $table->string('client', 120)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('working_days')->default(31)->after('fy_start_month');
        });

        // Every company starts with "Internal" for work that isn't for a client.
        $now = now();
        DB::table('companies')->orderBy('id')->each(function (object $company) use ($now): void {
            DB::table('projects')->insertOrIgnore([
                'company_id' => $company->id,
                'name' => 'Internal',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('working_days');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::dropIfExists('projects');
    }
};
