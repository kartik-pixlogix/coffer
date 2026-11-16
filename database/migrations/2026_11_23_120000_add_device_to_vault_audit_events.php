<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // VaultAudit.dc.html "Device" and "New device" (D75): the label is kept when the event is written.
        Schema::table('vault_audit_events', function (Blueprint $table) {
            $table->string('device', 64)->nullable()->after('user_agent');
            $table->boolean('new_device')->default(false)->after('device');
            $table->index(['company_id', 'user_id', 'device']);
        });
    }

    public function down(): void
    {
        Schema::table('vault_audit_events', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'user_id', 'device']);
            $table->dropColumn(['device', 'new_device']);
        });
    }
};
