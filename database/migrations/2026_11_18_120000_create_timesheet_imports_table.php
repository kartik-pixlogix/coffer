<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timesheet CSV imports (spec §8.2): the uploaded file (private disk), the column
     * mapping, and the counts of the queued commit.
     */
    public function up(): void
    {
        Schema::create('timesheet_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size');
            $table->unsignedInteger('rows');
            $table->json('header');
            $table->json('mapping')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedBigInteger('imported_centi')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            // The rows that were skipped, written when the import runs (after it, they'd read as duplicates).
            $table->string('errors_path')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreign('import_id')->references('id')->on('timesheet_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['import_id']);
        });

        Schema::dropIfExists('timesheet_imports');
    }
};
