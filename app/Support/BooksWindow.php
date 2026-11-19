<?php

declare(strict_types=1);

namespace App\Support;

use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;

/**
 * How far back a money date may go (G11, D116): the fiscal year before the company's
 * first one (the year it was set up, or of its earliest entry), so last year's books can
 * be caught up but a typo like 1990 is refused instead of disappearing from every list.
 */
final class BooksWindow
{
    public static function earliest(Company $company): CarbonImmutable
    {
        $first = JournalEntry::query()->withoutGlobalScope('company')->where('company_id', $company->id)->min('date');
        $created = CarbonImmutable::instance($company->created_at ?? now());
        $from = $first !== null ? CarbonImmutable::parse((string) $first)->min($created) : $created;

        return FiscalYears::startOf($company, $from)->subYear();
    }

    /** The validation rule and its message for a money date field. */
    public static function rule(Company $company): string
    {
        return 'after_or_equal:'.self::earliest($company)->toDateString();
    }

    public static function message(Company $company): string
    {
        $start = self::earliest($company);

        return 'Pick a date from '.$start->format('j M Y').' ('.FiscalYears::label($company, $start).') onwards.';
    }
}
