<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPDPA (D98): scheduled account and workspace deletions, and data export requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
            $table->timestamp('anonymised_at')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
            $table->foreignId('deletion_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deletion_requested_by');
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for', 'anonymised_at']);
        });
    }
};
