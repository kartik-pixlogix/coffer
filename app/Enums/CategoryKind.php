<?php

declare(strict_types=1);

namespace App\Enums;

/** Income (4xxx) or expense (5xxx) category. */
enum CategoryKind: string
{
    case Income = 'income';
    case Expense = 'expense';
}
