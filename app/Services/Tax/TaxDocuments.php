<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\GstSupply;
use App\Enums\TdsSection;
use App\Ledger\ExpenseTax;
use App\Ledger\IncomeTax;
use App\Models\Company;
use App\Models\DocumentTax;
use App\Services\Security\FieldCrypto;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Turns the "GST and TDS" part of the expense and income forms into the figures that post
 * and the `document_taxes` row that the returns read (D162). All checks live here, with
 * errors keyed `tax.*` for the forms:
 *
 * - the party: registered (a GSTIN, whose state decides the heads), unregistered (a state,
 *   or the company's own) or foreign (import or export);
 * - expenses: the supplier's GST as billed (within ₹1 of the rate) and the bill adding up to
 *   the amount; imports are reverse charge, the GST worked out at the rate; input credit only
 *   for a regular-registered company;
 * - income: the GST charged at the rate (none for exports, or when the company isn't
 *   regular-registered), and the amount received worked out;
 * - TDS by section, no more than the taxable value.
 */
final class TaxDocuments
{
    public function __construct(private readonly FieldCrypto $crypto) {}

    /**
     * @param  array<string, mixed>  $input  the form's `tax` object
     * @return array{tax: ExpenseTax, attributes: array<string, mixed>}
     */
    public function forExpense(Company $company, array $input, int $amount): array
    {
        $party = $this->party($company, $input, inward: true);
        $rate = $this->rate($input);
        $taxable = $this->int($input, 'taxable_paise');
        $reverse = $party['supply'] === GstSupply::Import;
        $errors = [];

        if ($taxable <= 0) {
            $errors['tax.taxable_paise'] = 'Enter the taxable value from the bill.';
        }

        if ($reverse) {
            // Reverse charge: nothing was charged on the bill; the company pays the GST.
            $heads = GstCalculator::tax($taxable, $rate, GstSupply::Import);

            if ($taxable !== $amount) {
                $errors['tax.taxable_paise'] = 'For an imported service the bill is the taxable value: it should be '.Money::format($amount).'.';
            }
        } else {
            $gst = $party['registered'] ? $this->int($input, 'gst_paise') : 0;
            $heads = GstCalculator::split($gst, $party['supply']);

            if ($taxable + $gst !== $amount) {
                $errors['tax.gst_paise'] = 'The taxable value plus GST ('.Money::format($taxable + $gst).') should equal the amount, '.Money::format($amount).'.';
            } elseif ($gst > 0 && ! GstCalculator::plausible($taxable, $gst, $rate, $party['supply'])) {
                $errors['tax.gst_paise'] = 'That GST isn’t '.GstRates::ALL[$rate].' of the taxable value. Check the rate or the amounts on the bill.';
            }
        }

        [$section, $tds] = $this->tds($input, $taxable, $errors);
        $this->fail($errors);

        $itc = $company->gst_registration->isRegular() && (bool) ($input['itc'] ?? true) && array_sum($heads) > 0;
        $tax = new ExpenseTax($taxable, $heads['cgst'], $heads['sgst'], $heads['igst'], $reverse, $itc, $section, $tds);

        return ['tax' => $tax, 'attributes' => [
            ...$this->common($company, DocumentTax::INWARD, $party, $input, $rate, $taxable, $heads),
            'reverse_charge' => $reverse,
            'itc' => $itc,
            'tds_section' => $section,
            'tds_paise' => $tds,
            'deductee_pan' => $section === null ? null : $this->pan($input),
        ]];
    }

    /**
     * @param  array<string, mixed>  $input  the form's `tax` object
     * @return array{tax: IncomeTax, attributes: array<string, mixed>, received: int}
     */
    public function forIncome(Company $company, array $input): array
    {
        $party = $this->party($company, $input, inward: false);
        $rate = $company->gst_registration->isRegular() ? $this->rate($input) : 0;
        $taxable = $this->int($input, 'taxable_paise');
        $errors = [];

        if ($taxable <= 0) {
            $errors['tax.taxable_paise'] = 'Enter the invoice’s taxable value.';
        }

        $heads = GstCalculator::tax($taxable, $rate, $party['supply']);
        [$section, $tds] = $this->tds($input, $taxable, $errors);
        $this->fail($errors);

        $tax = new IncomeTax($taxable, $heads['cgst'], $heads['sgst'], $heads['igst'], $section, $tds);

        return ['tax' => $tax, 'received' => $tax->received(), 'attributes' => [
            ...$this->common($company, DocumentTax::OUTWARD, $party, $input, $rate, $taxable, $heads),
            'reverse_charge' => false,
            'itc' => false,
            'tds_section' => $section,
            'tds_paise' => $tds,
            'deductee_pan' => null,
        ]];
    }

    /**
     * Saves, replaces or removes a document's tax row.
     *
     * @param  array<string, mixed>|null  $attributes
     */
    public function save(Model $document, ?array $attributes): void
    {
        /** @var DocumentTax|null $existing */
        $existing = DocumentTax::query()->withoutGlobalScopes()
            ->where('taxable_type', $document->getMorphClass())->where('taxable_id', $document->getKey())->first();

        if ($attributes === null) {
            $existing?->delete();

            return;
        }

        $row = $existing ?? new DocumentTax;
        $row->fill($attributes);
        $row->taxable_type = $document->getMorphClass();
        $row->taxable_id = (int) $document->getKey();
        $row->save();
    }

    public static function expenseTax(?DocumentTax $row): ?ExpenseTax
    {
        return $row === null ? null : new ExpenseTax(
            $row->taxable_paise, $row->cgst_paise, $row->sgst_paise, $row->igst_paise,
            $row->reverse_charge, $row->itc, $row->tds_section, $row->tds_paise,
        );
    }

    public static function incomeTax(?DocumentTax $row): ?IncomeTax
    {
        return $row === null ? null : new IncomeTax(
            $row->taxable_paise, $row->cgst_paise, $row->sgst_paise, $row->igst_paise, $row->tds_section, $row->tds_paise,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, gstin: string|null, state: string|null, supply: GstSupply, registered: bool}
     */
    private function party(Company $company, array $input, bool $inward): array
    {
        $kind = in_array($input['party'] ?? null, ['registered', 'unregistered', 'foreign'], true) ? (string) $input['party'] : 'unregistered';
        $gstin = $this->upper($input['gstin'] ?? null);
        $state = $this->upper($input['state'] ?? null);

        if ($kind === 'registered') {
            if ($gstin === null || preg_match(GstStates::GSTIN_PATTERN, $gstin) !== 1 || GstStates::ofGstin($gstin) === null) {
                throw ValidationException::withMessages(['tax.gstin' => 'Enter the '.($inward ? 'supplier’s' : 'client’s').' GSTIN: 15 characters, like 27ABCDE1234F1Z5.']);
            }

            $state = GstStates::ofGstin($gstin);
        } else {
            $gstin = null;
            $state = $kind === 'foreign' ? null : ($state !== null && GstStates::exists($state) ? $state : $company->state_code);
        }

        return [
            'kind' => $kind,
            'gstin' => $gstin,
            'state' => $state,
            'supply' => GstCalculator::supply($company->state_code, $state, $kind === 'foreign', $inward),
            'registered' => $kind === 'registered',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{kind: string, gstin: string|null, state: string|null, supply: GstSupply, registered: bool}  $party
     * @param  array{cgst: int, sgst: int, igst: int}  $heads
     * @return array<string, mixed>
     */
    private function common(Company $company, string $direction, array $party, array $input, int $rate, int $taxable, array $heads): array
    {
        $date = is_string($input['invoice_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['invoice_date']) === 1
            ? CarbonImmutable::parse((string) $input['invoice_date'])->toDateString() : null;

        return [
            'company_id' => $company->id,
            'direction' => $direction,
            'supply' => $party['supply'],
            'party_gstin' => $party['gstin'],
            'party_gstin_index' => $party['gstin'] === null ? null : $this->crypto->blindIndex($company->id, $party['gstin'], 'document_taxes.party_gstin'),
            'party_state' => $party['state'],
            'invoice_no' => $this->text($input['invoice_no'] ?? null, 32),
            'invoice_date' => $date,
            'hsn_sac' => $this->text(is_string($input['hsn_sac'] ?? null) ? preg_replace('/\D/', '', (string) $input['hsn_sac']) : null, 8),
            'rate_bps' => $rate,
            'taxable_paise' => $taxable,
            'cgst_paise' => $heads['cgst'],
            'sgst_paise' => $heads['sgst'],
            'igst_paise' => $heads['igst'],
        ];
    }

    /** @param array<string, mixed> $input */
    private function rate(array $input): int
    {
        $rate = $this->int($input, 'rate_bps');

        if (! GstRates::exists($rate)) {
            throw ValidationException::withMessages(['tax.rate_bps' => 'Pick a GST rate.']);
        }

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return array{0: TdsSection|null, 1: int}
     */
    private function tds(array $input, int $taxable, array &$errors): array
    {
        $section = is_string($input['tds_section'] ?? null) ? TdsSection::tryFrom((string) $input['tds_section']) : null;

        if ($section === null) {
            return [null, 0];
        }

        $tds = array_key_exists('tds_paise', $input) && is_numeric($input['tds_paise'])
            ? (int) $input['tds_paise']
            : (TdsCalculator::amount($taxable, $section) ?? 0);

        if ($tds <= 0 || $tds > $taxable) {
            $errors['tax.tds_paise'] = 'Enter the TDS held back: more than nothing, and no more than the taxable value.';
        }

        return [$section, $tds];
    }

    /** @param array<string, mixed> $input */
    private function pan(array $input): ?string
    {
        $pan = $this->upper($input['deductee_pan'] ?? null);

        if ($pan !== null && preg_match(GstStates::PAN_PATTERN, $pan) !== 1) {
            throw ValidationException::withMessages(['tax.deductee_pan' => 'A PAN is 10 characters, like AAJCV1234K.']);
        }

        // A registered supplier's PAN is inside their GSTIN.
        $gstin = $this->upper($input['gstin'] ?? null);

        return $pan ?? ($gstin !== null && preg_match(GstStates::GSTIN_PATTERN, $gstin) === 1 ? GstStates::panOf($gstin) : null);
    }

    /** @param array<string, string> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $input */
    private function int(array $input, string $key): int
    {
        return is_numeric($input[$key] ?? null) ? (int) $input[$key] : 0;
    }

    private function upper(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper((string) preg_replace('/\s+/', '', $value));

        return $value === '' ? null : $value;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
