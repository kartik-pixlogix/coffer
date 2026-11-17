<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vault imports (spec §14.4, D77). The upload is sealed with the company's data key
        // and deleted once it is imported or cancelled; `summary` holds counts and line numbers only.
        Schema::create('vault_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('format', 24);
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('key_version')->nullable();
            $table->unsignedInteger('size');
            $table->unsignedInteger('rows');
            $table->json('header')->nullable();
            $table->json('mapping')->nullable();
            $table->string('status', 16);
            $table->json('summary')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_imports');
    }
};
