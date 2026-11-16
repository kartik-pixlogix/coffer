<?php

declare(strict_types=1);

namespace App\Enums;

/** A timesheet import (spec §8.2): uploaded → mapped → queued → done (or failed). */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Queued = 'queued';
    case Done = 'done';
    case Failed = 'failed';

    public function isRunning(): bool
    {
        return $this === self::Queued;
    }

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
