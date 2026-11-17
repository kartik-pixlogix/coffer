<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads from the marketing site's Contact / Book a demo form (spec §21, D84). Not tenant
 * data: there is no company yet, so no company_id and no BelongsToCompany scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 20);
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('team_size', 20)->nullable();
            $table->text('message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
    }
};
