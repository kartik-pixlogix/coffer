<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Categories\SaveCategory;
use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Finance\CategoryUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Categories (spec §16): income and expense categories, add, rename and
 * archive. Salary is the system expense category salary runs post to, so it's locked.
 * Archived categories stay on old records but can't be picked for new ones.
 */
final class CategoriesController extends Controller
{
    public function index(CategoryUsage $usage): Response
    {
        $categories = Category::query()->orderBy('kind')->orderByRaw('archived_at IS NOT NULL')->orderBy('position')->orderBy('name')->get();
        $counts = $usage->counts($categories);

        return Inertia::render('settings/categories', [
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'kind' => $c->kind->value,
                'name' => $c->name,
                'system' => $c->system,
                'archived' => $c->archived_at !== null,
                'records' => $counts[$c->id] ?? 0,
            ])->values(),
        ]);
    }

    public function store(Request $request, SaveCategory $save): RedirectResponse
    {
        /** @var array{kind: string, name: string} $data */
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'name' => ['required', 'string', 'max:60'],
        ], ['name.required' => 'Add the category’s name.']);

        $category = $save->create(CategoryKind::from($data['kind']), $data['name']);

        activity('categories')->performedOn($category)->causedBy($request->user())->log('added a category');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$category->name} added"]);

        return back();
    }

    public function update(Request $request, Category $category, SaveCategory $save): RedirectResponse
    {
        $this->assertEditable($category);

        /** @var array{name: string} $data */
        $data = $request->validate(['name' => ['required', 'string', 'max:60']], ['name.required' => 'Add the category’s name.']);
        $save->rename($category, $data['name']);

        activity('categories')->performedOn($category)->causedBy($request->user())->log('renamed a category');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$category->name} updated"]);

        return back();
    }

    /** Archive or restore. */
    public function archive(Request $request, Category $category): RedirectResponse
    {
        $this->assertEditable($category);

        $archive = $category->archived_at === null;
        $category->update(['archived_at' => $archive ? now() : null]);

        activity('categories')->performedOn($category)->causedBy($request->user())->log($archive ? 'archived a category' : 'restored a category');
        Inertia::flash('toast', ['type' => 'success', 'message' => $archive ? "{$category->name} archived" : "{$category->name} restored"]);

        return back();
    }

    private function assertEditable(Category $category): void
    {
        if ($category->system) {
            throw ValidationException::withMessages(['category' => "{$category->name} is a system category, so it can’t be changed."]);
        }
    }
}
