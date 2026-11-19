<?php

declare(strict_types=1);

use App\Support\Csv;

/** G3 (D107): spreadsheet formula injection. */
test('cells a spreadsheet would run as formulas become text', function (string $in, string $out) {
    expect(Csv::cell($in))->toBe($out);
})->with([
    ['=HYPERLINK("http://x","y")', '\'=HYPERLINK("http://x","y")'],
    ['+91 98', "'+91 98"],
    ['-cmd|calc', "'-cmd|calc"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ["\tTab", "'\tTab"],
    ["\rCR", "'\rCR"],
    ['Meta ads', 'Meta ads'],
    ['', ''],
]);

test('plain numbers, nulls and booleans are left as values', function () {
    expect(Csv::cell('-1250.50'))->toBe('-1250.50')
        ->and(Csv::cell(-125))->toBe('-125')
        ->and(Csv::cell(null))->toBe('')
        ->and(Csv::cell(true))->toBe('1')
        ->and(Csv::row(['=1+1', 'ok', 3]))->toBe(["'=1+1", 'ok', '3']);
});

test('put writes a neutralised row', function () {
    $out = fopen('php://temp', 'r+');
    Csv::put($out, ['=evil()', 'fine']);
    rewind($out);

    expect(stream_get_contents($out))->toBe("'=evil(),fine\n");
});
