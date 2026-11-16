<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
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
            ...($user instanceof User ? $this->tenancy($user) : [
                'workspace' => null,
                'companies' => [],
                'currentCompany' => null,
                'can' => ['pages' => (object) [], 'actions' => (object) []],
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
    private function tenancy(User $user): array
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
        ];
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
