<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equity is stored in basis points of a percent: 10000 = 100.00% (two decimals, no floats).
     */
    public function up(): void
    {
        // JV only: a parent organisation with a share of the JV (spec §1).
        Schema::create('sides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->unsignedSmallInteger('equity_bps');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('side_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('role', 16);
            $table->unsignedTinyInteger('colour');
            // Share of the company (Single) or of the side (JV).
            $table->unsignedSmallInteger('equity_bps');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        // Team members (spec §7). Phase 4 adds rate history and the rest.
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('email')->nullable();
            $table->string('cost_type', 16);
            $table->bigInteger('rate_paise');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('sides');
    }
};
