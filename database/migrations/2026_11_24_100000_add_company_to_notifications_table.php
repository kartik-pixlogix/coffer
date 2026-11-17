<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications belong to a company (spec §19, D78): the bell and the Notifications page
 * show the current company's rows plus workspace-wide ones (billing), which have no company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('notifiable_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20)->nullable()->after('company_id');
            $table->index(['notifiable_type', 'notifiable_id', 'company_id', 'read_at'], 'notifications_inbox_index');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_inbox_index');
            $table->dropIndex(['created_at']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('category');
        });
    }
};
