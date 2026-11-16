<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an expense was paid (spec §10, Expenses.dc.html "Paid by"): one payer, 50/50
 * between two (odd paisa to the first), any number of lines, or straight from the
 * company account.
 */
enum PayerMode: string
{
    case Full = 'full';
    case Half = 'half';
    case Custom = 'custom';
    case Company = 'company';
}
