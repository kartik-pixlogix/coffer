<?php

declare(strict_types=1);

use App\Services\Timesheets\Hours;
use Carbon\CarbonImmutable;

test('hours parse from decimals and h:mm', function (string $input, ?int $centi) {
    expect(Hours::parse($input))->toBe($centi);
})->with([
    ['2.5', 250],
    ['2:30', 250],
    ['57:20', 5733],
    ['57.33', 5733],
    ['8', 800],
    ['.75', 75],
    ['7:05', 708],
    [' 25:00 ', 2500],
    ['', null],
    ['abc', null],
    ['2:75', null],
    ['-1', null],
    ['1,5', null],
]);

test('hours format like the design', function () {
    expect(Hours::format(800))->toBe('8.0')
        ->and(Hours::format(250))->toBe('2.5')
        ->and(Hours::format(5733))->toBe('57.33')
        ->and(Hours::format(101250))->toBe('1,012.5');
});

test('November 2026 has 21 weekdays, 25 with Saturdays', function () {
    $november = CarbonImmutable::parse('2026-11-01');

    expect(Hours::workingDays($november, Hours::WEEKDAYS))->toHaveCount(21)
        ->and(Hours::workingDays($november, Hours::mask(Hours::WEEKDAYS, true)))->toHaveCount(25)
        ->and(Hours::workingDays($november, Hours::WEEKDAYS)[0]->toDateString())->toBe('2026-11-02')
        ->and(Hours::mask(Hours::WEEKDAYS | Hours::SATURDAY, false))->toBe(Hours::WEEKDAYS);
});

test('month mode distributes the total exactly, spare hundredths on the earliest days', function () {
    expect(Hours::distribute(5733, 21))->toBe(array_fill(0, 21, 273))
        ->and(Hours::distribute(1000, 3))->toBe([334, 333, 333])
        ->and(Hours::distribute(5, 7))->toBe([1, 1, 1, 1, 1, 0, 0]);
});

test('distribution always sums to the total for every month and toggle (property)', function () {
    $month = CarbonImmutable::parse('2026-04-01');

    for ($i = 0; $i < 12; $i++, $month = $month->addMonth()) {
        foreach ([false, true] as $saturdays) {
            $days = count(Hours::workingDays($month, Hours::mask(Hours::WEEKDAYS, $saturdays)));

            for ($trial = 0; $trial < 25; $trial++) {
                $total = random_int(1, 24 * 100 * $days);
                $parts = Hours::distribute($total, $days);

                expect(array_sum($parts))->toBe($total)
                    ->and(max($parts) - min($parts))->toBeLessThanOrEqual(1);
            }
        }
    }
});
