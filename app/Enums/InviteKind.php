<?php

declare(strict_types=1);

namespace App\Enums;

/** An email invite is single-use; a share link can be used by several people until it expires. */
enum InviteKind: string
{
    case Email = 'email';
    case Link = 'link';
}
