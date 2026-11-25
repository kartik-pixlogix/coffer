<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Schedule III line each income or expense category reports under (D156). Null means
     * the default for its kind (ScheduleLine::defaultFor), so existing and new categories
     * need nothing until an Admin picks another line on the Chart of accounts.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('schedule_line', 32)->nullable()->after('system');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('schedule_line');
        });
    }
};
