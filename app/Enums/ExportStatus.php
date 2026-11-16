<?php

declare(strict_types=1);

namespace App\Enums;

/** A queued report export (spec §15, D61): queued → ready (or failed). */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';
}
