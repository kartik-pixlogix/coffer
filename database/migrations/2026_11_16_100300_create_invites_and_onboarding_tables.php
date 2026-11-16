<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec §2.3. Tokens are looked up by hash; the encrypted copy lets the share link be shown again.
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('kind', 8);
            $table->string('email')->nullable();
            $table->string('role', 16);
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->dateTime('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'expires_at']);
        });

        // "Save and exit" in the create-company wizard (spec §3.1).
        Schema::create('company_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step')->default(1);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        // "Trust this device for 30 days" at the 2FA challenge (spec §17).
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        // "Ask an Admin for access" on the permission-denied page.
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page', 32);
            $table->string('reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('company_drafts');
        Schema::dropIfExists('invites');
    }
};
