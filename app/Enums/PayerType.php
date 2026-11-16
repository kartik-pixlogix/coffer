<?php

declare(strict_types=1);

namespace App\Enums;

/** Who paid one line of an expense (spec §10): a partner, a team member or the company. */
enum PayerType: string
{
    case Company = 'company';
    case Partner = 'partner';
    case Member = 'member';
}
