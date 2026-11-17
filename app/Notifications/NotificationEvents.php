<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Every notification event (spec §19, D78) with its category, the label and hint on
 * Settings › Notifications, and the channels a user may not turn off. Keys are what
 * `users.notification_preferences` stores.
 */
final class NotificationEvents
{
    /** Panel and page filters, in order. */
    public const array CATEGORIES = [
        'approvals' => 'Approvals',
        'security' => 'Security',
        'money' => 'Money',
        'workspace' => 'Team & billing',
    ];

    /** Delivery channels: the bell and the Notifications page, and email. */
    public const array CHANNELS = ['database', 'mail'];

    /**
     * @var array<string, array{category: string, label: string, hint: string, required?: list<string>}>
     */
    public const array EVENTS = [
        'expense.submitted' => ['category' => 'approvals', 'label' => 'An expense needs approval', 'hint' => 'When someone adds an expense you can approve.'],
        'expense.decided' => ['category' => 'approvals', 'label' => 'Your expense is approved or rejected', 'hint' => 'With the reason when it’s rejected.'],
        'vault.access_requested' => ['category' => 'security', 'label' => 'Vault access requested', 'hint' => 'For vaults and items you manage.'],
        'vault.access_decided' => ['category' => 'security', 'label' => 'Your vault request is decided', 'hint' => 'Approved or denied by a Manager.'],
        'vault.expiring' => ['category' => 'security', 'label' => 'A credential is expiring', 'hint' => '30, 7 and 1 days before.'],
        'vault.breach_found' => ['category' => 'security', 'label' => 'A password was found in a breach', 'hint' => 'From the weekly breach check, when it’s on.'],
        'settlement.due' => ['category' => 'money', 'label' => 'A settlement is due', 'hint' => 'When one side owes the other more than the threshold.'],
        'fy.reminder' => ['category' => 'money', 'label' => 'A fiscal year is ready to close', 'hint' => 'Once a month after the year ends, until it’s closed.'],
        'fy.closed' => ['category' => 'money', 'label' => 'A fiscal year was closed', 'hint' => 'With each partner’s closing capital carried forward.'],
        'access.requested' => ['category' => 'workspace', 'label' => 'Someone asks to open a page', 'hint' => 'For Admins: a role can’t open a page and asks for it.'],
        'invite.accepted' => ['category' => 'workspace', 'label' => 'An invite is accepted', 'hint' => 'For the person who sent it.'],
        'salary.run' => ['category' => 'workspace', 'label' => 'Salaries are posted', 'hint' => 'After the monthly salary run.'],
        'import.finished' => ['category' => 'workspace', 'label' => 'An import finished or failed', 'hint' => 'Timesheet and vault imports you started.'],
        'billing.trial_ending' => ['category' => 'workspace', 'label' => 'The free trial is ending', 'hint' => '3 days and 1 day before. Owner and Admins.'],
        'billing.payment_failed' => ['category' => 'workspace', 'label' => 'A payment failed', 'hint' => 'Always emailed, so billing never stops by surprise.', 'required' => ['mail']],
    ];

    public static function category(string $event): string
    {
        return self::EVENTS[$event]['category'] ?? 'workspace';
    }

    /** Whether `$channel` is always on for `$event` (the user can't turn it off). */
    public static function required(string $event, string $channel): bool
    {
        return in_array($channel, self::EVENTS[$event]['required'] ?? [], true);
    }

    /** @return list<string> */
    public static function inCategory(string $category): array
    {
        return array_keys(array_filter(self::EVENTS, fn (array $e) => $e['category'] === $category));
    }
}
