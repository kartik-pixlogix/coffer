<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NotificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Notifications (spec §16 "per-user, per-event email and in-app toggles", D78):
 * every event grouped by category, with In app and Email switches. It's the user's own
 * choice, so anyone in a company can open it — not just those with the Settings page.
 */
final class NotificationsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('settings/notifications', [
            'groups' => array_map(fn (string $category, string $label) => [
                'key' => $category,
                'label' => $label,
                'events' => array_map(fn (string $event) => [
                    'key' => $event,
                    'label' => NotificationEvents::EVENTS[$event]['label'],
                    'hint' => NotificationEvents::EVENTS[$event]['hint'],
                    'database' => $user->wantsNotification($event, 'database'),
                    'mail' => $user->wantsNotification($event, 'mail'),
                    'required' => NotificationEvents::EVENTS[$event]['required'] ?? [],
                ], NotificationEvents::inCategory($category)),
            ], array_keys(NotificationEvents::CATEGORIES), NotificationEvents::CATEGORIES),
            'email' => $user->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $rules = [];

        foreach (array_keys(NotificationEvents::EVENTS) as $event) {
            foreach (NotificationEvents::CHANNELS as $channel) {
                // Event keys hold dots ("expense.submitted"), escaped so they aren't read as nesting.
                $rules['preferences.'.str_replace('.', '\.', $event).".{$channel}"] = ['sometimes', 'boolean'];
            }
        }

        $request->validate(['preferences' => ['required', 'array'], ...$rules]);
        $saved = $user->notification_preferences ?? [];
        $input = $request->input('preferences');

        foreach (is_array($input) ? $input : [] as $event => $channels) {
            if (! is_string($event) || ! array_key_exists($event, NotificationEvents::EVENTS) || ! is_array($channels)) {
                continue;
            }

            foreach ($channels as $channel => $on) {
                if (in_array($channel, NotificationEvents::CHANNELS, true)) {
                    $saved[$event][$channel] = NotificationEvents::required($event, $channel) || filter_var($on, FILTER_VALIDATE_BOOL);
                }
            }
        }

        $user->forceFill(['notification_preferences' => $saved])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification settings saved']);

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
