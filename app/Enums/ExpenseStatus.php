<?php

declare(strict_types=1);

namespace App\Enums;

/** Spec §10: Pending → Approved / Rejected. Only approved expenses post (spec §4.3). */
enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
