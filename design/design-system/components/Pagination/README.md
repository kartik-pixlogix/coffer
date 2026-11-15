# Pagination

Use pagination under any table longer than one page: the range and total on the left ("Showing 1–10 of 24 expenses"), page buttons on the right.

- Markup: `<nav class="cf-pager" aria-label="Pagination">` › `cf-pagebtn` items; the current one has `aria-current="page"` (ink fill); previous/next are `cf-pagebtn--edge` icon buttons and disable at the ends; collapse long runs with an ellipsis.
