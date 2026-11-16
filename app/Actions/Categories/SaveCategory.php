<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\Enums\AccountRole;
use App\Enums\CategoryKind;
use App\Ledger\ChartOfAccounts;
use App\Models\Account;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and renames income and expense categories (spec §16, and "+ Add category" in the
 * expense form, spec §10). Names are unique per company and kind, ignoring case; the
 * category's ledger account (4xxx / 5xxx) is created with it and renamed with it.
 */
final class SaveCategory
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    public function create(CategoryKind $kind, string $name, string $field = 'name'): Category
    {
        $name = $this->clean($name);
        $this->assertUnique($kind, $name, null, $field);

        return DB::transaction(function () use ($kind, $name): Category {
            /** @var Category $category */
            $category = Category::query()->create([
                'kind' => $kind,
                'name' => $name,
                'system' => false,
                'position' => 1 + (int) Category::query()->where('kind', $kind->value)->max('position'),
            ]);

            $kind === CategoryKind::Income ? $this->chart->income($category) : $this->chart->expense($category);

            return $category;
        });
    }

    public function rename(Category $category, string $name): void
    {
        $name = $this->clean($name);
        $this->assertUnique($category->kind, $name, $category->id, 'name');

        DB::transaction(function () use ($category, $name): void {
            $category->update(['name' => $name]);

            $role = $category->kind === CategoryKind::Income ? AccountRole::Income : AccountRole::Expense;
            $prefix = $category->kind === CategoryKind::Income ? 'Income — ' : 'Expenses — ';

            Account::query()->withoutGlobalScope('company')
                ->where('company_id', $category->company_id)
                ->where('role', $role->value)
                ->where('category_id', $category->id)
                ->update(['name' => $prefix.$name]);
        });
    }

    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private function assertUnique(CategoryKind $kind, string $name, ?int $ignore, string $field): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([$field => 'Add the category’s name.']);
        }

        $taken = Category::query()
            ->where('kind', $kind->value)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => 'There’s already a category with this name.']);
        }
    }
}
