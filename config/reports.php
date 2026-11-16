<?php

declare(strict_types=1);

return [
    /*
    | Exports (spec §15 "queued if large", D61). A period with more journal lines than this
    | is exported by a queued job and offered as a download when it's ready; smaller ones
    | stream straight away.
    */
    'queue_exports_over_lines' => (int) env('REPORTS_QUEUE_EXPORTS_OVER_LINES', 20000),
];
