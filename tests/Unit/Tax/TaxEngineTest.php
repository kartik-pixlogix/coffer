<?php

declare(strict_types=1);

use App\Enums\GstSupply;
use App\Enums\TdsSection;
use App\Services\Tax\GstCalculator;
use App\Services\Tax\GstRates;
use App\Services\Tax\GstStates;
use App\Services\Tax\TdsCalculator;

/*
| The tax engine (D159, D160): GST heads by place of supply and TDS by section, in paise.
| Pure arithmetic, so every rate and case is checked here. [VERIFY] with the CA.
*/

test('the place of supply follows the parties', function (?string $party, bool $foreign, bool $inward, GstSupply $expected) {
    expect(GstCalculator::supply('27', $party, $foreign, $inward))->toBe($expected);
})->with([
    'same state' => ['27', false, true, GstSupply::Intra],
    'unregistered buyer, no state' => [null, false, false, GstSupply::Intra],
    'another state' => ['29', false, false, GstSupply::Inter],
    'service bought from abroad' => [null, true, true, GstSupply::Import],
    'service sold abroad' => [null, true, false, GstSupply::Export],
]);

test('every rate splits into CGST and SGST within the state and IGST across states', function (int $bps) {
    $taxable = 1_00_000_00;
    $intra = GstCalculator::tax($taxable, $bps, GstSupply::Intra);
    $inter = GstCalculator::tax($taxable, $bps, GstSupply::Inter);
    $expected = intdiv($taxable * $bps, 10000);

    expect($intra['cgst'])->toBe($intra['sgst'])
        ->and($intra['cgst'] + $intra['sgst'])->toBe($expected)
        ->and($intra['igst'])->toBe(0)
        ->and($inter)->toBe(['cgst' => 0, 'sgst' => 0, 'igst' => $expected])
        ->and(GstCalculator::tax($taxable, $bps, GstSupply::Import))->toBe($inter)
        ->and(GstCalculator::tax($taxable, $bps, GstSupply::Export))->toBe(['cgst' => 0, 'sgst' => 0, 'igst' => 0]);
})->with(GstRates::values());

test('each half is rounded on its own, the way an invoice shows it', function () {
    // 18% of ₹10.05 = ₹1.809: 9% is 90.45 paise each, rounded to 90 each.
    expect(GstCalculator::tax(10_05, 1800, GstSupply::Intra))->toBe(['cgst' => 90, 'sgst' => 90, 'igst' => 0])
        ->and(GstCalculator::tax(10_05, 1800, GstSupply::Inter))->toBe(['cgst' => 0, 'sgst' => 0, 'igst' => 181]);
});

test('the actual tax on a bill splits with the odd paisa on CGST', function () {
    expect(GstCalculator::split(1_81, GstSupply::Intra))->toBe(['cgst' => 91, 'sgst' => 90, 'igst' => 0])
        ->and(GstCalculator::split(1_81, GstSupply::Inter))->toBe(['cgst' => 0, 'sgst' => 0, 'igst' => 1_81])
        ->and(GstCalculator::split(1_81, GstSupply::Export))->toBe(['cgst' => 0, 'sgst' => 0, 'igst' => 0]);
});

test('the taxable value comes back out of a tax-inclusive total', function (int $bps, int $total, int $taxable) {
    expect(GstCalculator::taxableFromTotal($total, $bps))->toBe($taxable);
})->with([
    '18%' => [1800, 1_18_000_00, 1_00_000_00],
    '5%' => [500, 10_500_00, 10_000_00],
    '40%' => [4000, 14_000_00, 10_000_00],
    'nil' => [0, 5_000_00, 5_000_00],
    'rounds to the paisa' => [1800, 1_000_00, 847_46],
]);

test('the tax on a bill is accepted within a rupee of the rate', function () {
    expect(GstCalculator::plausible(1_00_000_00, 18_000_00, 1800, GstSupply::Intra))->toBeTrue()
        ->and(GstCalculator::plausible(1_00_000_00, 18_000_99, 1800, GstSupply::Inter))->toBeTrue()
        ->and(GstCalculator::plausible(1_00_000_00, 18_002_00, 1800, GstSupply::Inter))->toBeFalse()
        ->and(GstCalculator::plausible(1_00_000_00, 5_000_00, 1800, GstSupply::Intra))->toBeFalse();
});

test('every TDS section has its rate, and salary has none', function (TdsSection $section, ?int $bps, string $code) {
    expect($section->rateBps())->toBe($bps)
        ->and($section->code())->toBe($code)
        ->and(TdsCalculator::amount(1_00_000_00, $section))->toBe($bps === null ? null : intdiv(1_00_000_00 * $bps, 10000));
})->with([
    'salary' => [TdsSection::Salary, null, '192'],
    'contractor, individual' => [TdsSection::ContractorIndividual, 100, '194C'],
    'contractor, firm' => [TdsSection::ContractorOther, 200, '194C'],
    'professional' => [TdsSection::Professional, 1000, '194J'],
    'technical' => [TdsSection::Technical, 200, '194J'],
    'rent, building' => [TdsSection::RentBuilding, 1000, '194I'],
    'rent, plant' => [TdsSection::RentPlant, 200, '194I'],
    'commission' => [TdsSection::Commission, 200, '194H'],
    'purchase of goods' => [TdsSection::PurchaseOfGoods, 10, '194Q'],
]);

test('vendor sections leave out salary', function () {
    expect(collect(TdsSection::vendorOptions())->pluck('value')->all())->not->toContain('192')->toHaveCount(8);
});

test('GSTINs give their state and PAN', function () {
    expect(GstStates::ofGstin('27AAJCV1234K1Z8'))->toBe('27')
        ->and(GstStates::ofGstin('99AAJCV1234K1Z8'))->toBeNull()
        ->and(GstStates::panOf('27AAJCV1234K1Z8'))->toBe('AAJCV1234K')
        ->and(preg_match(GstStates::GSTIN_PATTERN, '27AAJCV1234K1Z8'))->toBe(1)
        ->and(preg_match(GstStates::TAN_PATTERN, 'PNEV01234B'))->toBe(1)
        ->and(GstStates::name('29'))->toBe('Karnataka');
});
