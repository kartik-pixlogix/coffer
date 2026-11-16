<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team rate history (spec §7) and timesheet entries (spec §8.1). Hours are integer
     * hundredths of an hour (2.5 h = 250), like money in paise (D35).
     */
    public function up(): void
    {
        // A rate change takes effect from a date and keeps history; past salaries don't change.
        Schema::create('member_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('cost_type', 16);
            $table->bigInteger('rate_paise');
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'effective_from']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->date('deactivated_on')->nullable()->after('status');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('member_id')->constrained();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->date('date');
            $table->unsignedInteger('hours_centi');
            $table->boolean('billable')->default(true);
            $table->string('notes', 500)->nullable();
            // Month-mode logs and imports share a batch, so the batch can be deleted together.
            $table->uuid('batch')->nullable();
            $table->unsignedBigInteger('import_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['member_id', 'date']);
            $table->index('batch');
        });

        // One opening rate per existing member, from the start of the fiscal year they joined in.
        $now = now();
        DB::table('members')
            ->join('companies', 'companies.id', '=', 'members.company_id')
            ->select('members.id', 'members.cost_type', 'members.rate_paise', 'members.created_at', 'companies.fy_start_month')
            ->orderBy('members.id')
            ->each(function (object $member) use ($now): void {
                $joined = CarbonImmutable::parse($member->created_at ?? $now);
                $start = $joined->startOfMonth()->month((int) $member->fy_start_month);

                DB::table('member_rates')->insert([
                    'member_id' => $member->id,
                    'cost_type' => $member->cost_type,
                    'rate_paise' => $member->rate_paise,
                    'effective_from' => ($start->greaterThan($joined) ? $start->subYear() : $start)->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('deactivated_on');
        });

        Schema::dropIfExists('member_rates');
    }
};
