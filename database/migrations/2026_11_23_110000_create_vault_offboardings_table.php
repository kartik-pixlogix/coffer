<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec §14.2 offboarding (D73): what a person could use when they left or lost access.
        Schema::create('vault_offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 255);
            $table->string('reason', 16);
            $table->json('item_ids');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_offboardings');
    }
};
