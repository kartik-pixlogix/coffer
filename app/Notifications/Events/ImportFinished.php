<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Notifications\CofferNotification;

/** A timesheet or vault import you started finished or failed (spec §19 "import finished"). */
final class ImportFinished extends CofferNotification
{
    public static function timesheets(int $companyId, bool $failed, string $summary): self
    {
        return new self(
            $companyId,
            $failed ? 'Your timesheet import failed' : 'Your timesheet import finished',
            $summary,
            route('timesheets.index'),
            ['type' => $failed ? 'failed' : 'done', 'id' => 0],
        );
    }

    public static function vault(int $companyId, bool $failed, string $summary): self
    {
        return new self(
            $companyId,
            $failed ? 'Your vault import failed' : 'Your vault import finished',
            $summary,
            route('vault.index'),
            ['type' => $failed ? 'failed' : 'done', 'id' => 0],
        );
    }

    public function event(): string
    {
        return 'import.finished';
    }

    public function icon(): string
    {
        return 'upload';
    }

    public function tone(): string
    {
        return $this->subject !== null && $this->subject['type'] === 'failed' ? 'neg' : 'plain';
    }

    public function linkLabel(): string
    {
        return 'Open';
    }
}
