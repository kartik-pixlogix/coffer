<?php

declare(strict_types=1);

use App\Services\Tax\GstSetOff;

/* GST set-off (D163): credit against output tax in the legal order; the rest in cash. */

$heads = fn (int $igst = 0, int $cgst = 0, int $sgst = 0): array => ['igst' => $igst, 'cgst' => $cgst, 'sgst' => $sgst];

test('credit on each head pays its own head first', function () use ($heads) {
    $r = GstSetOff::settle($heads(1000, 900, 900), $heads(), $heads(400, 300, 300));

    expect($r['cash'])->toBe($heads(600, 600, 600))
        ->and($r['usedByCredit'])->toBe($heads(400, 300, 300))
        ->and($r['creditLeft'])->toBe($heads());
});

test('spare IGST credit pays CGST, then SGST', function () use ($heads) {
    $r = GstSetOff::settle($heads(0, 500, 500), $heads(), $heads(800));

    expect($r['used']['igst'])->toBe($heads(0, 500, 300))
        ->and($r['cash'])->toBe($heads(0, 0, 200))
        ->and($r['creditLeft'])->toBe($heads());
});

test('spare CGST and SGST credit go to IGST, never to each other', function () use ($heads) {
    $r = GstSetOff::settle($heads(1000, 0, 300), $heads(), $heads(0, 600, 0));

    expect($r['used']['cgst'])->toBe($heads(600, 0, 0))
        ->and($r['cash'])->toBe($heads(400, 0, 300))
        ->and($r['creditLeft'])->toBe($heads());

    $cross = GstSetOff::settle($heads(0, 0, 500), $heads(), $heads(0, 500, 0));
    expect($cross['cash'])->toBe($heads(0, 0, 500))->and($cross['creditLeft'])->toBe($heads(0, 500, 0));
});

test('IGST credit is used before CGST and SGST credit on IGST', function () use ($heads) {
    $r = GstSetOff::settle($heads(500), $heads(), $heads(300, 300, 300));

    expect($r['used']['igst']['igst'])->toBe(300)
        ->and($r['used']['cgst']['igst'])->toBe(200)
        ->and($r['used']['sgst']['igst'])->toBe(0)
        ->and($r['creditLeft'])->toBe($heads(0, 100, 300));
});

test('reverse-charge GST is always paid in cash, even with credit to spare', function () use ($heads) {
    $r = GstSetOff::settle($heads(), $heads(3600), $heads(5000));

    expect($r['cash'])->toBe($heads(3600))
        ->and($r['cashTotal'])->toBe(3600)
        ->and($r['creditLeft'])->toBe($heads(5000));
});

test('more credit than tax is carried forward', function () use ($heads) {
    $r = GstSetOff::settle($heads(100, 100, 100), $heads(), $heads(1000, 50, 50));

    expect($r['cashTotal'])->toBe(0)
        ->and(array_sum($r['usedByCredit']))->toBe(300)
        ->and(array_sum($r['creditLeft']))->toBe(1100 - 300);
});
