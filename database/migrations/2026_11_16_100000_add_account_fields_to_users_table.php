<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Profile fields from spec §17: time zone, number format and photo. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('theme');
            $table->string('number_format', 16)->default('indian')->after('timezone');
            $table->string('avatar_path')->nullable()->after('number_format');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'number_format', 'avatar_path']);
        });
    }
};
