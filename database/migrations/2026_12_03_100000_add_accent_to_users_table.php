<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's brand gradient (D147): null is Coffer lime.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('accent')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('accent');
        });
    }
};
