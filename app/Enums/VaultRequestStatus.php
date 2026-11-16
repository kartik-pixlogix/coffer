<?php

declare(strict_types=1);

namespace App\Enums;

/** An access request's state (VaultShare.dc.html tabs: Pending, Approved, Denied). */
enum VaultRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
