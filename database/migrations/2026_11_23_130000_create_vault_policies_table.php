<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec §14.4 "Policies" (VaultLockPolicies.dc.html, D76): one row per company; no row = the defaults.
        Schema::create('vault_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained();
            $table->unsignedSmallInteger('reauth_minutes');
            $table->boolean('fresh_code_each_reveal');
            $table->unsignedSmallInteger('idle_lock_minutes');
            $table->boolean('lock_on_close');
            $table->unsignedSmallInteger('clipboard_clear_seconds');
            $table->unsignedSmallInteger('reveal_seconds');
            $table->string('export_who', 16);
            $table->boolean('export_reason');
            $table->boolean('breach_checks');
            $table->unsignedSmallInteger('generator_min_length');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_policies');
    }
};
