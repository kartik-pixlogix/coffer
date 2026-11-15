# Notification panel

Use the notification panel from the top-bar bell for things that need someone's attention (approvals, expiring credentials, settlements due); each row links straight to the record, and unread rows carry a blue dot.

- Markup: `cf-pop cf-notif` (400 px) › `cf-notif-head` with Mark all read › `cf-notif-item` links with a typed `cf-notif-ic` (`--warn` approvals, `--neg` security, `--lime` money), a bold line, a caption with source and age, and `cf-unread`.
- The bell's badge count equals the unread rows; the full history lives on the Notifications page.
