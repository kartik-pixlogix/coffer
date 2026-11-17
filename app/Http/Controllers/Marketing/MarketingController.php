<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\PlanCatalog;
use App\Support\Marketing\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public site (spec §21, D83): Landing, Pricing, the feature-detail template, Security
 * and the legal pages. Server-rendered (Inertia SSR) for search engines and link previews.
 */
final class MarketingController extends Controller
{
    /** `/`: the landing page; someone signed in goes straight to their landing page (§2.1). */
    public function landing(Request $request, PlanCatalog $catalog): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('home');
        }

        return Inertia::render('marketing/landing', [
            'plans' => $catalog->all(),
            'seo' => Seo::for($request, $this->plans()),
        ]);
    }

    public function pricing(Request $request, PlanCatalog $catalog): Response
    {
        return Inertia::render('marketing/pricing', [
            'plans' => $catalog->all(),
            'defaultCurrency' => config('billing.default_currency') === 'usd' ? 'usd' : 'inr',
            'trialDays' => (int) config('billing.trial_days'),
            'seo' => Seo::for($request, $this->plans()),
        ]);
    }

    public function feature(Request $request, string $feature): Response
    {
        return Inertia::render('marketing/feature', [
            'feature' => $feature,
            'seo' => Seo::for($request),
        ]);
    }

    public function security(Request $request): Response
    {
        return Inertia::render('marketing/security', [
            'seo' => Seo::for($request),
        ]);
    }

    public function legal(Request $request, string $page): Response
    {
        return Inertia::render('marketing/legal', [
            'page' => $page,
            'seo' => Seo::for($request),
        ]);
    }

    /** @return Collection<int, Plan> */
    private function plans(): Collection
    {
        return Plan::query()->orderBy('position')->get()->toBase();
    }
}
