# Toast

Use a toast to confirm that something just happened or failed (sent for approval, copied, deleted with Undo); it sits top-right, stays about 2.6 seconds (errors stay until dismissed) and never holds information the person needs later.

- Markup: `cf-toast` (graphite pill) › `cf-toast-ic` + message + optional `cf-toast-action`. Variants: success (lime disc), `cf-toast--error` (`role="alert"`), `cf-toast--info`. Add `cf-toast--fixed` to pin it to the viewport.
- Slides in with `cfToastIn`; reduced motion simply shows it.
