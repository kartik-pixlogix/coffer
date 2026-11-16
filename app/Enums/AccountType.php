<?php

declare(strict_types=1);

namespace App\Enums;

/** Account class in the chart (spec §4.1). */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
