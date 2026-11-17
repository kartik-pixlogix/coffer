<?php

namespace App\Http\Middleware;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PlanUsage;
use App\Services\Billing\WorkspaceBilling;
use App\Services\Notifications\Inbox;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use App\Support\Permissions\Catalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user instanceof User ? $this->user($user) : null,
            ],
            ...($user instanceof User ? $this->tenancy($user, $request) : [
                'workspace' => null,
                'companies' => [],
                'currentCompany' => null,
                'can' => ['pages' => (object) [], 'actions' => (object) []],
                'notifications' => null,
                'billingStatus' => null,
                'planLimit' => null,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'avatar_url' => $user->avatar_url,
            'theme' => $user->theme?->value,
            'timezone' => $user->timezone,
            'number_format' => $user->number_format->value,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    /**
     * The shell's switcher, breadcrumb and nav rail: every company the user belongs to
     * (with their role), the current one, and what its permission matrix lets them open.
     *
     * @return array<string, mixed>
     */
    private function tenancy(User $user, Request $request): array
    {
        $current = app(CurrentCompany::class);
        $company = $current->get();
        $access = app(Access::class);

        $companies = $user->companies()->with('workspace:id,name')->get();

        return [
            'workspace' => $company === null ? null : [
                'id' => $company->workspace_id,
                'name' => $company->workspace->name,
                'isOwner' => $company->workspace->isOwnedBy($user),
            ],
            'companies' => $companies->map(fn (Company $c) => $this->summary($c, $c->getRelation('membership')))->values(),
            'currentCompany' => $company === null ? null : $this->summary($company, $company->getRelation('membership')),
            'can' => [
                'pages' => $company === null ? (object) [] : $access->pages($user),
                'actions' => $company === null ? (object) [] : $access->actions($user),
            ],
            // The bell's badge only; the panel loads its rows when it opens (D78).
            'notifications' => $company === null ? null : fn () => ['unread' => app(Inbox::class)->unread($user)],
            'billingStatus' => $company === null ? null : fn () => $this->billingStatus($company->workspace, $access->allows($user, Catalog::pagePermission('billing'))),
            'planLimit' => $company === null ? null : fn () => $this->planLimit($request, $company->workspace),
        ];
    }

    /**
     * The shell's billing banner (D80, D81): trial days left, a failed payment, read-only.
     *
     * @return array<string, mixed>
     */
    private function billingStatus(Workspace $workspace, bool $canManage): array
    {
        $state = app(WorkspaceBilling::class)->for($workspace);

        return [
            'status' => $state->status,
            'plan' => $state->plan?->name,
            'trialDaysLeft' => $state->trialDaysLeft(),
            'readOnly' => $state->isReadOnly(),
            'graceEndsAt' => $state->graceEndsAt?->timezone('Asia/Kolkata')->format('j M Y'),
            'canManage' => $canManage,
            'owner' => $workspace->owner->name,
        ];
    }

    /**
     * "Near the limit" for the page that adds the thing (D82): users on Settings › Members,
     * vault items in the vault, companies in the create-company wizard.
     *
     * @return array{key: string, label: string, noun: string, used: int, limit: int, full: bool}|null
     */
    private function planLimit(Request $request, Workspace $workspace): ?array
    {
        $limit = match ($request->route()?->getName()) {
            'settings.members' => PlanLimit::Users,
            'vault.index', 'vault.items.create', 'vault.import' => PlanLimit::VaultItems,
            'companies.create' => PlanLimit::Companies,
            default => null,
        };

        if ($limit === null) {
            return null;
        }

        $state = app(WorkspaceBilling::class)->for($workspace);
        $max = $state->plan?->limit($limit);

        if ($max === null || ! $state->isManaged()) {
            return null;
        }

        $used = app(PlanUsage::class)->used($workspace, $limit);

        if ($used < (float) config('billing.near_limit') * $max) {
            return null;
        }

        return ['key' => $limit->value, 'label' => $limit->label(), 'noun' => $limit->noun($max), 'used' => $used, 'limit' => $max, 'full' => $used >= $max];
    }

    /** @return array<string, mixed> */
    private function summary(Company $company, Membership $membership): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'initials' => $company->initials(),
            'type' => $company->type->value,
            'role' => $membership->role->value,
            'workspace' => $company->relationLoaded('workspace') ? $company->workspace->name : null,
        ];
    }
}
