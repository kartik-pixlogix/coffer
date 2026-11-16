<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials Vault (spec §14, D66–D71). Secrets live only in `vault_secrets`,
 * `vault_attachments` (bytes on the private disk) and `vault_item_versions`, always as
 * AES-256-GCM ciphertext under the company's data key; every other column is metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each company's data keys, wrapped by VAULT_MASTER_KEY. The highest live version seals new data.
        Schema::create('vault_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedInteger('version');
            $table->text('wrapped_key');
            // Which master key wrapped it: the first 16 hex characters of its SHA-256.
            $table->string('master_key_id', 16);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'version']);
        });

        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 80);
            $table->string('type', 16)->default('shared');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('vault_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_id')->constrained();
            $table->string('type', 24);
            $table->string('provider', 40)->nullable();
            $table->string('name', 160);
            // The list's second line ("root@203.0.113.24:22"), built from non-secret fields.
            $table->string('subtitle')->nullable();
            $table->string('username')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('environment', 16)->nullable();
            // Non-secret template values and custom-field definitions (secret values are in vault_secrets).
            $table->json('fields')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('tags')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            // zxcvbn-style score 0–4 of the item's main password; null when it has none.
            $table->unsignedTinyInteger('strength')->nullable();
            $table->boolean('has_totp')->default(false);
            // Only explicit item grants reach it (vault access doesn't), e.g. "AWS root — Admins only".
            $table->boolean('restricted')->default(false);
            $table->boolean('breached')->default(false);
            $table->timestamp('breach_checked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'vault_id']);
            $table->index(['company_id', 'expires_at']);
        });

        // One row per secret field. AAD = "vault_secret:{item}:{field}".
        Schema::create('vault_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_item_id')->constrained()->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('ciphertext');
            $table->unsignedInteger('key_version');
            // HMAC-SHA256 of the value under the company's fingerprint key (reuse detection, spec §14.3).
            $table->char('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['vault_item_id', 'field']);
            $table->index(['company_id', 'fingerprint']);
            $table->index(['company_id', 'key_version']);
        });

        // Encrypted bytes on the private disk. AAD = "vault_attachment:{id}".
        Schema::create('vault_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime', 128)->nullable();
            $table->unsignedInteger('size');
            $table->string('path');
            $table->unsignedInteger('key_version');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'key_version']);
        });

        // Encrypted snapshots of the whole item after each save (spec §14.4 "Version history").
        Schema::create('vault_item_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('reason', 16);
            $table->unsignedInteger('restored_from')->nullable();
            $table->longText('snapshot');
            $table->unsignedInteger('key_version');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->unique(['vault_item_id', 'version']);
            $table->index(['company_id', 'key_version']);
        });

        // Access (spec §14.2): a grant on a whole vault or on one item, optionally ending on a date.
        Schema::create('vault_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vault_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('level', 8);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['vault_id', 'user_id']);
            $table->unique(['vault_item_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('vault_favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vault_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('created_at')->nullable();

            $table->unique(['vault_item_id', 'user_id']);
        });

        // Append-only (spec §14.4 "Audit log", §23 "vault events are immutable"). The item's
        // name is copied so the log still reads after the item is deleted.
        Schema::create('vault_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->unsignedBigInteger('vault_id')->nullable();
            $table->unsignedBigInteger('vault_item_id')->nullable();
            $table->string('item_name', 160)->nullable();
            $table->string('action', 24);
            $table->string('field', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->index(['company_id', 'created_at']);
            $table->index(['vault_item_id', 'created_at']);
            $table->index(['company_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_audit_events');
        Schema::dropIfExists('vault_favourites');
        Schema::dropIfExists('vault_grants');
        Schema::dropIfExists('vault_item_versions');
        Schema::dropIfExists('vault_attachments');
        Schema::dropIfExists('vault_secrets');
        Schema::dropIfExists('vault_items');
        Schema::dropIfExists('vaults');
        Schema::dropIfExists('vault_keys');
    }
};
