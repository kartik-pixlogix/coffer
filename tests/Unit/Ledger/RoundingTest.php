<?php

declare(strict_types=1);

use App\Ledger\Rounding;

test('shares add up exactly, the leftover going to the largest remainders', function () {
    expect(Rounding::largestRemainder(100, [1 => 1, 2 => 1, 3 => 1]))->toBe([1 => 34, 2 => 33, 3 => 33])
        ->and(Rounding::largestRemainder(10_00, [6000, 4000]))->toBe([6_00, 4_00])
        ->and(Rounding::largestRemainder(1, [5000, 5000]))->toBe([1, 0]);
});

test('negative amounts split the same way', function () {
    expect(Rounding::largestRemainder(-100, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => -34, 'b' => -33, 'c' => -33]);
});

test('big amounts and bps × bps weights do not overflow', function () {
    $total = 1_000_00_00_000_00; // ₹1,000 crore
    $parts = Rounding::largestRemainder($total, [3000_0000, 2000_0000, 2500_0000, 2500_0000]);

    expect(array_sum($parts))->toBe($total)
        ->and($parts[0])->toBe(300_00_00_000_00);
});

test('a share of zero weight gets nothing and nothing to split is fine', function () {
    expect(Rounding::largestRemainder(999, [1 => 0, 2 => 3]))->toBe([1 => 0, 2 => 999])
        ->and(Rounding::largestRemainder(0, []))->toBe([]);
});

test('a non-zero amount needs weights', function () {
    Rounding::largestRemainder(5, [0, 0]);
})->throws(InvalidArgumentException::class);

test('random splits always sum to the total', function () {
    mt_srand(42);

    foreach (range(1, 500) as $_) {
        $total = mt_rand(-10_000_000, 10_000_000);
        $weights = array_map(fn () => mt_rand(0, 10_000), range(1, mt_rand(1, 7)));

        if (array_sum($weights) === 0) {
            continue;
        }

        expect(array_sum(Rounding::largestRemainder($total, $weights)))->toBe($total);
    }
});
