<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension history per workspace (S6, D118): who suspended it, when and why, and when
 * (and by whom) it was lifted. The workspace row keeps only the current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->foreignId('suspended_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('suspended_at');
            $table->timestamp('unsuspended_at')->nullable();
            $table->foreignId('unsuspended_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'suspended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_suspensions');
    }
};
