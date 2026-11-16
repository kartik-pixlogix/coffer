<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The paying tenant (spec §2). Owns the subscription and its companies.
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        // A set of books (spec §3).
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained();
            $table->string('name');
            $table->string('short_code', 6);
            $table->string('type', 16);
            $table->char('currency', 3)->default('INR');
            $table->unsignedTinyInteger('fy_start_month')->default(4);
            $table->string('attribution_mode', 16);
            $table->bigInteger('opening_balance_paise')->default(0);
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'short_code']);
        });

        // One role per user per company. `role` is canonical; spatie's team roles mirror it.
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('workspaces');
    }
};
