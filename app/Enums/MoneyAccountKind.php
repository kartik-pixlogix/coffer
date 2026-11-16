<?php

declare(strict_types=1);

namespace App\Enums;

/** Where cash sits (spec §1): the company account, a JV side's account, or an extra bank/cash account. */
enum MoneyAccountKind: string
{
    case Company = 'company';
    case Side = 'side';
    case Bank = 'bank';
    case Cash = 'cash';
}
