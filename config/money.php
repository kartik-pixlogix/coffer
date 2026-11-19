<?php

return [

    /*
    | The largest amount any money field accepts (F1, D116), in paise. ₹100 crore by
    | default; a typo like an extra four zeros is refused instead of posted. The hard
    | ceiling is Money::MAX_PAISE (₹1,000 crore).
    */
    'max_paise' => (int) env('MONEY_MAX_PAISE', 100_00_00_000_00),

];
