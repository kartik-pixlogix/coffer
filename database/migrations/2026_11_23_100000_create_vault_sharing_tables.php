<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec §14.2 access requests (VaultShare.dc.html "Access requests", D72).
        Schema::create('vault_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('vault_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vault_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('level', 8);
            $table->string('duration', 8);
            $table->string('reason', 500);
            $table->string('status', 12);
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'user_id']);
        });

        // Laravel database notifications (spec §19). Phase 8 builds the panel; vault events store them now.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Per-user, per-event channel choices (spec §16 "Notifications"); Phase 8 adds the screen.
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('vault_access_requests');
    }
};
