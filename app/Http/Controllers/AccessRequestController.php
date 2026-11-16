<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessRequest;
use App\Models\AccessRequest;
use App\Models\User;
use App\Notifications\AccessRequested;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/** "Ask an Admin for access" and "Cancel request" on the permission-denied page. */
final class AccessRequestController extends Controller
{
    public function store(StoreAccessRequest $request, CurrentCompany $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();

        $accessRequest = AccessRequest::query()->firstOrCreate(
            ['user_id' => $user->id, 'page' => $request->string('page')->value(), 'cancelled_at' => null],
            ['reason' => $request->filled('reason') ? $request->string('reason')->value() : null],
        );

        if ($accessRequest->wasRecentlyCreated) {
            $admins = $company->admins()->reject(fn (User $admin) => $admin->id === $user->id);
            Notification::send($admins, new AccessRequested($accessRequest, $user, $company));
        }

        return back();
    }

    public function destroy(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        abort_unless($accessRequest->user_id === $request->user()?->id, 404);

        $accessRequest->update(['cancelled_at' => now()]);

        return back();
    }
}
