<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ⌘K palette's record search (F5, D116): people, partners, expenses, income,
 * projects and (while the vault is unlocked) vault item names, in the current company
 * only (the company scope), each group only for a role that may open that page. Names and
 * descriptions only: never a secret, never another company's row. Rate-limited.
 */
final class SearchController extends Controller
{
    private const int PER_GROUP = 5;

    public function __invoke(Request $request, CurrentCompany $current, VaultAccess $vault, VaultSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));

        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $member = $current->role() === CompanyRole::Member;
        $groups = [];

        if ($user->can('page.team')) {
            $groups[] = ['label' => 'People', 'items' => Member::query()->where('name', 'like', $like)
                ->when($member, fn (Builder $b) => $b->where('user_id', $user->id))
                ->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Member $m) => ['id' => 'member:'.$m->id, 'label' => $m->name, 'hint' => $m->designation, 'href' => route('team.show', $m)])->all()];
        }

        if ($user->can('page.partners')) {
            $groups[] = ['label' => 'Partners', 'items' => Partner::query()->active()->where('name', 'like', $like)
                ->orderBy('position')->limit(self::PER_GROUP)->get()
                ->map(fn (Partner $p) => ['id' => 'partner:'.$p->id, 'label' => $p->name, 'href' => route('partners.index')])->all()];
        }

        if ($user->can('page.expenses')) {
            $groups[] = ['label' => 'Expenses', 'items' => Expense::query()->where('description', 'like', $like)
                ->when($member, fn (Builder $b) => $b->where('submitted_by', $user->id))
                ->latest('spent_on')->limit(self::PER_GROUP)->get()
                ->map(fn (Expense $e) => ['id' => 'expense:'.$e->id, 'label' => $e->description, 'hint' => Money::format($e->amount_paise).' · '.$e->spent_on->format('j M Y'), 'href' => route('expenses.index', ['q' => $e->description])])->all()];
        }

        if ($user->can('page.income')) {
            $groups[] = ['label' => 'Income', 'items' => Income::query()->where('source', 'like', $like)
                ->latest('received_on')->limit(self::PER_GROUP)->get()
                ->map(fn (Income $i) => ['id' => 'income:'.$i->id, 'label' => $i->source, 'hint' => Money::format($i->amount_paise).' · '.$i->received_on->format('j M Y'), 'href' => route('income.index')])->all()];
        }

        if ($user->can('page.timesheets')) {
            $groups[] = ['label' => 'Projects', 'items' => Project::query()->where('name', 'like', $like)
                ->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Project $p) => ['id' => 'project:'.$p->id, 'label' => $p->name, 'href' => route('timesheets.index', ['project' => $p->id])])->all()];
        }

        // Vault names only while unlocked, and only items this person can see.
        if ($user->can('page.vault') && $session->opensFor($user)) {
            $needle = mb_strtolower($q);
            $groups[] = ['label' => 'Vault', 'items' => $vault->visibleItems($user)
                ->filter(fn (VaultItem $i) => str_contains(mb_strtolower($i->name), $needle))
                ->take(self::PER_GROUP)
                ->map(fn (VaultItem $i) => ['id' => 'vault:'.$i->id, 'label' => $i->name, 'monogram' => $i->monogram(), 'href' => route('vault.index', ['item' => $i->id])])
                ->values()->all()];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn (array $g) => $g['items'] !== []))]);
    }
}
