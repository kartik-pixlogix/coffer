<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 performance pass (D92): composite indexes for the list filters, led by
 * company_id so the tenant scope uses them. MySQL indexes foreign keys on its own, but
 * PostgreSQL doesn't, so the filter columns get explicit (company_id, column, date)
 * indexes that serve both and keep the date ordering.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private array $indexes = [
        'expenses' => [['company_id', 'category_id', 'spent_on'], ['company_id', 'project_id', 'spent_on'], ['company_id', 'submitted_by', 'spent_on']],
        'expense_payers' => [['expense_id']],
        'incomes' => [['company_id', 'category_id', 'received_on'], ['company_id', 'project_id', 'received_on']],
        'time_entries' => [['company_id', 'project_id', 'date']],
        'journal_lines' => [['journal_entry_id']],
        'capital_movements' => [['company_id', 'partner_id', 'moved_on']],
        'loan_repayments' => [['loan_id', 'paid_on']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex($columns);
                }
            });
        }
    }
};
