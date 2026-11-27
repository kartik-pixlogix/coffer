<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\TdsSection;
use App\Models\Company;
use App\Models\DocumentTax;

/**
 * What the expense and income forms need to work tax out (D162): the company's registration
 * and state, the GST rates and states, TDS sections and common SAC / HSN codes; and a saved
 * document's tax as the forms and drawers show it.
 */
final class TaxOptions
{
    /** Common codes for an agency or studio, as suggestions (any code may be typed). [VERIFY] */
    public const array CODES = [
        '998311' => 'Management consulting',
        '998313' => 'IT consulting and support',
        '998314' => 'IT design and development',
        '998315' => 'Hosting and IT infrastructure',
        '998316' => 'IT infrastructure and network management',
        '998319' => 'Other IT services',
        '997331' => 'Licensing of software',
        '998361' => 'Advertising services',
        '998221' => 'Accounting and auditing',
        '997212' => 'Renting of commercial property',
        '996331' => 'Restaurant and catering',
        '996412' => 'Air travel',
        '998599' => 'Other support services',
        '8471' => 'Computers and laptops',
        '8517' => 'Phones and network equipment',
    ];

    /** @return array<string, mixed> */
    public static function for(Company $company): array
    {
        $codes = [];

        foreach (self::CODES as $code => $label) {
            $codes[] = ['code' => (string) $code, 'label' => $label];
        }

        return [
            'registered' => $company->gst_registration->isRegular(),
            'stateCode' => $company->state_code,
            'states' => GstStates::options(),
            'rates' => GstRates::options(),
            'tdsSections' => array_values(array_map(
                fn (TdsSection $s) => ['value' => $s->value, 'label' => $s->label(), 'rateBps' => $s->rateBps()],
                array_filter(TdsSection::cases(), fn (TdsSection $s) => $s !== TdsSection::Salary),
            )),
            'codes' => $codes,
        ];
    }

    /** @return array<string, mixed>|null a saved document's tax, as the forms and drawers read it */
    public static function row(?DocumentTax $tax): ?array
    {
        if ($tax === null) {
            return null;
        }

        return [
            'party' => $tax->party_gstin !== null ? 'registered' : (in_array($tax->supply?->value, ['import', 'export'], true) ? 'foreign' : 'unregistered'),
            'gstin' => $tax->party_gstin,
            'state' => $tax->party_state,
            'supply' => $tax->supply?->value,
            'invoiceNo' => $tax->invoice_no,
            'invoiceDate' => $tax->invoice_date?->toDateString(),
            'hsnSac' => $tax->hsn_sac,
            'rateBps' => $tax->rate_bps,
            'taxablePaise' => $tax->taxable_paise,
            'cgstPaise' => $tax->cgst_paise,
            'sgstPaise' => $tax->sgst_paise,
            'igstPaise' => $tax->igst_paise,
            'reverseCharge' => $tax->reverse_charge,
            'itc' => $tax->itc,
            'tdsSection' => $tax->tds_section?->value,
            'tdsSectionLabel' => $tax->tds_section?->label(),
            'tdsPaise' => $tax->tds_paise,
        ];
    }
}
