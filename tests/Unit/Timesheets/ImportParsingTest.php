<?php

declare(strict_types=1);

use App\Services\Timesheets\Import\ColumnMapper;
use App\Services\Timesheets\Import\ImportDates;

test('every spec §8.2 date format parses', function (string $raw, string $kind, string $iso) {
    $parsed = ImportDates::parse($raw);

    expect($parsed['kind'])->toBe($kind)
        ->and($parsed['date']->toDateString())->toBe($iso);
})->with([
    ['2026-04-01', 'day', '2026-04-01'],
    ['01/04/2026', 'day', '2026-04-01'],
    ['1-Apr-26', 'day', '2026-04-01'],
    ['1 April 2026', 'day', '2026-04-01'],
    ['13-Oct-26', 'day', '2026-10-13'],
    ['1.4.26', 'day', '2026-04-01'],
    ['2026-04', 'month', '2026-04-01'],
]);

test('impossible and unreadable dates', function () {
    expect(ImportDates::parse('31-Sep-26'))->toBe(['kind' => 'impossible', 'message' => '31 September doesn’t exist.'])
        ->and(ImportDates::parse('30/02/2026'))->toBe(['kind' => 'impossible', 'message' => '30 February doesn’t exist.'])
        ->and(ImportDates::parse('yesterday'))->toBeNull()
        ->and(ImportDates::parse('2026-13'))->toBeNull()
        ->and(ImportDates::parse('1-Foo-26'))->toBeNull();
});

test('numeric dates are day-first unless the file shows month-first', function () {
    expect(ImportDates::order(['01/04/2026', '02/04/2026']))->toBe(ImportDates::DAY_FIRST)
        ->and(ImportDates::order(['04/13/2026', '04/01/2026']))->toBe(ImportDates::MONTH_FIRST)
        ->and(ImportDates::parse('04/13/2026', ImportDates::MONTH_FIRST)['date']->toDateString())->toBe('2026-04-13')
        ->and(ImportDates::explain(['13/10/2026', '01/10/2026']))->toBe('Dates read as day-month-year because one row has 13/10/2026.')
        ->and(ImportDates::explain(['1-Oct-26']))->toBeNull();
});

test('columns match by name and alias, and Item Name becomes the project', function () {
    $guess = ColumnMapper::guess(['Log owner', 'Log Date', 'Log Hours', 'Item Name', 'Billable?', 'Notes'], [], []);

    expect($guess['mapping'])->toBe(['member', 'date', 'hours', 'project', 'billable', 'notes'])
        ->and(ColumnMapper::missing($guess['mapping']))->toBe([]);

    $withProject = ColumnMapper::guess(['developer', 'date', 'hours', 'project', 'Item Name'], [], []);
    expect($withProject['mapping'][4])->toBe('notes');
});

test('an unnamed column of team members is matched by its values', function () {
    $guess = ColumnMapper::guess(['Who', 'Date', 'Time spent', 'Project'], [['Rohan Desai', '1-Oct-26', '2:30', 'Saarthi'], ['Sana Qureshi', '1-Oct-26', '8', 'Saarthi']], ['rohan desai', 'sana qureshi']);

    expect($guess['mapping'][0])->toBe('member')
        ->and($guess['how'][0])->toBe('values')
        ->and(ColumnMapper::missing([null, 'date', 'hours', null]))->toBe(['member', 'project']);
});
