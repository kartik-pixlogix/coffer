<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NotificationEvents;
use App\Services\Notifications\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifications (spec §19, Notifications.dc.html, D78): the bell's panel (loaded when it
 * opens), the full `/notifications` page with filters and pages, and marking rows read.
 * Everyone signed in to a company has them; rows are their own, in this company.
 */
final class NotificationsController extends Controller
{
    public function index(Request $request, Inbox $inbox): Response
    {
        $user = $this->user($request);
        $filter = $this->filter($request);
        $page = $inbox->query($user, $filter)->latest()->paginate((int) config('notifications.per_page'))->withQueryString();

        return Inertia::render('notifications/index', [
            'filter' => $filter,
            'counts' => $inbox->counts($user),
            'categories' => NotificationEvents::CATEGORIES,
            'items' => $inbox->present($page->getCollection(), $user),
            'pagination' => [
                'page' => $page->currentPage(),
                'pageCount' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /** The bell's panel, fetched when it opens (the shared prop only carries the unread count). */
    public function panel(Request $request, Inbox $inbox): JsonResponse
    {
        $user = $this->user($request);
        $rows = $inbox->query($user, $this->filter($request))->latest()->limit((int) config('notifications.panel_limit'))->get();

        return response()->json([
            'items' => $inbox->present($rows, $user),
            'counts' => $inbox->counts($user),
        ]);
    }

    public function read(Request $request, Inbox $inbox, string $notification): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);
        /** @var DatabaseNotification $row */
        $row = $inbox->query($user)->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return $request->expectsJson() && $request->header('X-Inertia') === null
            ? response()->json(['unread' => $inbox->unread($user)])
            : back();
    }

    public function readAll(Request $request, Inbox $inbox): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);
        $inbox->query($user, $this->filter($request))->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() && $request->header('X-Inertia') === null
            ? response()->json(['unread' => $inbox->unread($user)])
            : back();
    }

    private function filter(Request $request): string
    {
        $request->validate(['filter' => ['nullable', Rule::in(['all', 'unread', ...array_keys(NotificationEvents::CATEGORIES)])]]);

        return $request->string('filter', 'all')->value();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
