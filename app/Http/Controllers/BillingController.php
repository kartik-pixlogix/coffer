<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\Billing\PaymentGateway;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanUsage;
use App\Services\Billing\WorkspaceBilling;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Billing (spec §20, Billing.dc.html, D80): the workspace's plan and trial, live usage,
 * invoices, payment method and the Upgrade modal. The page is the Billing permission —
 * the workspace owner and Admins, never grantable (D29). Card details never touch Coffer:
 * Checkout and the Billing Portal are Stripe's hosted pages.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly WorkspaceBilling $billing,
        private readonly PaymentGateway $gateway,
    ) {}

    public function index(Request $request, PlanUsage $usage, PlanCatalog $catalog): Response
    {
        $workspace = $this->workspace();
        $checkout = in_array($request->query('checkout'), ['done', 'cancelled'], true) ? (string) $request->query('checkout') : null;

        // B2 (D117): back from Checkout, read the session from Stripe and sync now, as the
        // webhook would (webhooks don't reach localhost, and they can lag in production).
        $sessionId = $request->query('session_id');

        if ($checkout === 'done' && is_string($sessionId) && preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) === 1 && $this->gatewayReady()) {
            try {
                $checkout = $this->gateway->syncCheckout($workspace, $sessionId) ? 'synced' : 'done';
            } catch (ApiErrorException $e) {
                Log::warning('Billing: Checkout Session could not be read', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
            }

            $workspace->refresh();
        }

        $state = $this->billing->for($workspace);
        [$invoices, $invoicesFailed] = $this->invoices($workspace);

        return Inertia::render('billing/index', [
            'state' => [
                'status' => $state->status,
                'plan' => $state->plan === null ? null : ['key' => $state->plan->key, 'name' => $state->plan->name],
                'cycle' => $state->cycle,
                'currency' => $state->currency,
                'trialEndsAt' => $state->trialEndsAt?->timezone('Asia/Kolkata')->format('j M Y'),
                'trialDaysLeft' => $state->trialDaysLeft(),
                'renewsAt' => $workspace->renews_at?->timezone('Asia/Kolkata')->format('j M Y'),
                'endsAt' => $state->subscription?->ends_at?->timezone('Asia/Kolkata')->format('j M Y'),
                'graceEndsAt' => $state->graceEndsAt?->timezone('Asia/Kolkata')->format('j M Y'),
                'readOnly' => $state->isReadOnly(),
                'hasSubscription' => $state->hasSubscription(),
                'paymentMethod' => $workspace->pm_last_four === null ? null : ['brand' => ucfirst((string) $workspace->pm_type), 'last4' => $workspace->pm_last_four],
                'workspace' => $workspace->name,
                'ownerName' => $workspace->owner->name,
            ],
            'details' => [
                'email' => $workspace->billing_email ?? $workspace->owner->email,
                'name' => $workspace->billing_name,
                'gstin' => $workspace->billing_gstin,
            ],
            'usage' => $this->usage($workspace, $usage),
            'invoices' => $invoices,
            'invoicesFailed' => $invoicesFailed,
            'plans' => $catalog->all(),
            'gatewayReady' => $this->gatewayReady(),
            // F6 (D118): the note about Stripe keys is for developers (local) only.
            'gatewayHint' => app()->isLocal(),
            'checkout' => $checkout,
        ]);
    }

    /** Plans for the Upgrade modal opened from anywhere (a limit, a banner); any role may look. */
    public function plans(PlanCatalog $catalog, PlanUsage $usage): JsonResponse
    {
        $workspace = $this->workspace();
        $state = $this->billing->for($workspace);
        $counts = $usage->usage($workspace);

        return response()->json([
            'plans' => $catalog->all(),
            'current' => ['plan' => $state->plan?->key, 'cycle' => $state->cycle, 'currency' => $state->currency, 'status' => $state->status, 'hasSubscription' => $state->hasSubscription()],
            'workspace' => ['name' => $workspace->name, 'companies' => $counts['companies']['used'], 'users' => $counts['users']['used'], 'owner' => $workspace->owner->name],
            'gatewayReady' => $this->gatewayReady(),
            'gatewayHint' => app()->isLocal(),
        ]);
    }

    /** The Upgrade modal's confirm: Checkout for a first subscription, else a prorated swap. */
    public function checkout(Request $request): RedirectResponse|HttpResponse
    {
        /** @var array{plan: string, cycle: string, currency?: string|null} $data */
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'key')],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'currency' => ['nullable', Rule::in((array) config('billing.currencies'))],
        ]);
        $workspace = $this->workspace();
        $state = $this->billing->for($workspace);
        /** @var Plan $plan */
        $plan = Plan::query()->where('key', $data['plan'])->firstOrFail();

        if ($plan->custom_pricing) {
            throw ValidationException::withMessages(['plan' => 'Enterprise is set up with our team. Talk to us and we’ll switch your plan.']);
        }

        if (! $this->gatewayReady()) {
            throw ValidationException::withMessages(['plan' => self::unavailable()]);
        }

        $this->assertFits($workspace, $plan);

        // B3 (D117): Stripe may know a subscription this server hasn't heard of yet (a
        // webhook that never arrived): sync it first, so it's changed in place, not doubled.
        if (! $state->hasSubscription() && $workspace->hasStripeId() && $this->stripe(fn () => $this->gateway->syncFromStripe($workspace))) {
            $state = $this->billing->for($workspace->refresh());
        }

        $currency = $state->hasSubscription() ? $state->currency : $this->currencyFor($plan, $data['cycle'], $data['currency'] ?? null, $state->currency);

        // A live subscription (also a cancelled one still paid up) is changed in place, never doubled.
        if ($state->hasSubscription()) {
            $resuming = $state->status === 'canceled';

            if (! $resuming && $state->plan?->is($plan) && $state->cycle === $data['cycle']) {
                throw ValidationException::withMessages(['plan' => 'This is already your plan.']);
            }

            $this->stripe(fn () => $this->gateway->swap($workspace, $plan, $data['cycle'], $currency));
            $workspace->forceFill(['plan_id' => $plan->id, 'billing_cycle' => $data['cycle']])->save();
            activity('billing')->performedOn($workspace)->causedBy($request->user())
                ->withProperties(['plan' => $plan->key, 'cycle' => $data['cycle']])->log($resuming ? 'resumed the plan' : 'changed the plan');
            Inertia::flash('toast', ['type' => 'success', 'message' => $resuming
                ? "{$plan->name} is back on. It renews as usual; nothing was cancelled."
                : "You’re on {$plan->name} now. The new limits are on."]);

            return back();
        }

        $url = $this->stripe(fn () => $this->gateway->checkout(
            $workspace,
            $plan,
            $data['cycle'],
            $currency,
            route('billing.index', ['checkout' => 'done']).'&session_id={CHECKOUT_SESSION_ID}',
            route('billing.index', ['checkout' => 'cancelled']),
        ));
        activity('billing')->performedOn($workspace)->causedBy($request->user())
            ->withProperties(['plan' => $plan->key, 'cycle' => $data['cycle'], 'currency' => $currency])->log('started checkout');

        return Inertia::location($url);
    }

    /** Stripe's Billing Portal: card, invoices, cancel. */
    public function portal(): HttpResponse
    {
        if (! $this->gatewayReady()) {
            throw ValidationException::withMessages(['portal' => self::unavailable()]);
        }

        $workspace = $this->workspace();

        return Inertia::location($this->stripe(fn () => $this->gateway->portal($workspace, route('billing.index'))));
    }

    public function invoice(string $invoice): HttpResponse
    {
        abort_unless(preg_match('/^in_[A-Za-z0-9]+$/', $invoice) === 1, 404);

        return $this->stripe(fn () => $this->gateway->downloadInvoice($this->workspace(), $invoice));
    }

    public function details(Request $request): RedirectResponse
    {
        if (is_string($request->input('gstin'))) {
            $request->merge(['gstin' => strtoupper(trim($request->input('gstin')))]);
        }

        /** @var array{email: string, name?: string|null, gstin?: string|null} $data */
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            // Same GSTIN check as Settings › Company (D63).
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
        ], ['gstin.regex' => 'That isn’t a valid GSTIN, e.g. 27ABCDE1234F1Z5.']);

        $workspace = $this->workspace();
        $workspace->forceFill([
            'billing_email' => $data['email'],
            'billing_name' => ($data['name'] ?? '') !== '' ? $data['name'] : null,
            'billing_gstin' => ($data['gstin'] ?? '') !== '' ? $data['gstin'] : null,
        ])->save();

        activity('billing')->performedOn($workspace)->causedBy($request->user())->log('updated billing details');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing details saved. New invoices use them.']);

        return back();
    }

    /** "Refresh from Stripe" (B2): pull the subscription and card straight from Stripe. */
    public function sync(Request $request): RedirectResponse
    {
        if (! $this->gatewayReady()) {
            throw ValidationException::withMessages(['plan' => self::unavailable()]);
        }

        $workspace = $this->workspace();
        $live = $this->stripe(fn () => $this->gateway->syncFromStripe($workspace));
        activity('billing')->performedOn($workspace)->causedBy($request->user())->log('refreshed from Stripe');

        Inertia::flash('toast', $live
            ? ['type' => 'success', 'message' => 'Up to date with Stripe.']
            : ['type' => 'info', 'message' => 'Stripe has no subscription for this workspace yet.']);

        return back();
    }

    /** What customers see while the server has no Stripe keys (F6); setup is in docs/BILLING_TESTING.md. */
    public static function unavailable(): string
    {
        return 'Online payments aren’t available yet. Contact support.';
    }

    /**
     * B6 (D118): a plan whose limits are below what the workspace already uses can't be
     * chosen; the message lists what's over.
     */
    private function assertFits(Workspace $workspace, Plan $plan): void
    {
        $usage = app(PlanUsage::class);
        $over = [];

        foreach (PlanLimit::cases() as $limit) {
            $max = $plan->limit($limit);
            $used = $usage->used($workspace, $limit);

            if ($max !== null && $used > $max) {
                $over[] = $limit === PlanLimit::Storage
                    ? "storage ({$used} MB of {$max} MB)"
                    : strtolower($limit->label())." ({$used} of {$max})";
            }
        }

        if ($over !== []) {
            throw ValidationException::withMessages(['plan' => "{$plan->name} is too small for this workspace: ".implode(', ', $over).'. Remove some first, or pick a bigger plan.']);
        }
    }

    /** B5 (D118): never default to a currency the plan can't be paid in. */
    private function currencyFor(Plan $plan, string $cycle, ?string $asked, string $current): string
    {
        foreach (array_filter([$asked, $current, ...(array) config('billing.currencies')]) as $currency) {
            if ($plan->stripePrice($cycle, (string) $currency) !== null) {
                return (string) $currency;
            }
        }

        return $asked ?? $current;
    }

    /** @return list<array{key: string, label: string, used: int, limit: int|null, detail: string}> */
    private function usage(Workspace $workspace, PlanUsage $usage): array
    {
        $counts = $usage->usage($workspace);
        $companies = Company::query()->where('workspace_id', $workspace->id)->orderBy('name')->pluck('name');
        $vaults = Vault::query()->withoutGlobalScope('company')->whereIn('company_id', Company::query()->where('workspace_id', $workspace->id)->select('id'))->count();

        $details = [
            PlanLimit::Companies->value => $companies->implode(', '),
            PlanLimit::Users->value => 'Across all '.$companies->count().' '.($companies->count() === 1 ? 'company' : 'companies').' · each person counts once',
            PlanLimit::VaultItems->value => 'Across '.$vaults.' '.($vaults === 1 ? 'vault' : 'vaults').' · attachments don’t count',
            PlanLimit::Storage->value => 'Receipts, income attachments and vault files',
        ];

        return array_map(fn (PlanLimit $limit) => [
            'key' => $limit->value,
            'label' => $limit->label(),
            'used' => $counts[$limit->value]['used'],
            'limit' => $counts[$limit->value]['limit'],
            'detail' => $details[$limit->value],
        ], PlanLimit::cases());
    }

    /** @return array{0: list<array<string, string>>, 1: bool} rows, and whether Stripe couldn't be reached */
    private function invoices(Workspace $workspace): array
    {
        if (! $this->gatewayReady()) {
            return [[], false];
        }

        try {
            return [$this->gateway->invoices($workspace), false];
        } catch (ApiErrorException $e) {
            Log::warning('Billing: invoices could not be loaded', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);

            return [[], true];
        }
    }

    /**
     * Runs a Stripe call; an API failure becomes a message on the page, never a stack trace.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function stripe(callable $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            Log::warning('Billing: Stripe call failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['plan' => 'Stripe couldn’t be reached just now. Nothing was charged; try again in a minute.']);
        }
    }

    private function gatewayReady(): bool
    {
        return filled(config('cashier.secret')) && filled(config('cashier.key'));
    }

    private function workspace(): Workspace
    {
        return $this->current->require()->workspace;
    }
}
