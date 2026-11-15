# Skeleton loader

Use skeletons while data loads for more than about 300 ms, shaped like the real content (KPI tiles, bars, list rows), so the layout doesn't jump when the data arrives.

- Classes: `cf-skel` plus `--line`, `--title` or `--circle`; size them inline. Mark the loading region `aria-busy="true"` with an `aria-label`.
- Keep showing the page shell and real headings; only the data is a skeleton.
