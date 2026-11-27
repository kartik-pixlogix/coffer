import type { FormDataConvertible } from '@inertiajs/core';
import type { TaxDraft, TaxOptions, TaxParty, TaxRow } from '@/types/tax';

/**
 * The arithmetic and payload of the "GST and TDS" part of the expense and income forms
 * (D162), mirroring App\Services\Tax so the figures shown are the figures that post. The
 * server checks everything again.
 */

export type Supply = 'intra' | 'inter' | 'import' | 'export';

export function supplyOf(
    draft: TaxDraft,
    options: TaxOptions,
    inward: boolean,
): Supply {
    if (draft.party === 'foreign') {
        return inward ? 'import' : 'export';
    }

    const state =
        draft.party === 'registered'
            ? draft.gstin.slice(0, 2)
            : draft.state || options.stateCode;

    return !state || state === options.stateCode ? 'intra' : 'inter';
}

/** The tax on a taxable value: CGST and SGST each at half the rate, rounded on their own. */
export function gstFor(
    taxable: number,
    rateBps: number,
    supply: Supply,
): number {
    if (supply === 'export') {
        return 0;
    }

    if (supply === 'intra') {
        return 2 * Math.round((taxable * rateBps) / 20000);
    }

    return Math.round((taxable * rateBps) / 10000);
}

export function taxableFromTotal(total: number, rateBps: number): number {
    return Math.round((total * 10000) / (10000 + rateBps));
}

export function tdsFor(
    taxable: number,
    section: string,
    options: TaxOptions,
): number | null {
    const rate = options.tdsSections.find((s) => s.value === section)?.rateBps;

    return rate === undefined || rate === null
        ? null
        : Math.round((taxable * rate) / 10000);
}

export function blankTax(options: TaxOptions, date: string): TaxDraft {
    return {
        party: 'registered',
        gstin: '',
        state: options.stateCode ?? '',
        invoiceNo: '',
        invoiceDate: date,
        hsnSac: '',
        rateBps: options.registered ? 1800 : 0,
        taxable: null,
        gst: null,
        itc: options.registered,
        tdsSection: '',
        tds: null,
        pan: '',
        taxableTouched: false,
        gstTouched: false,
        tdsTouched: false,
    };
}

export function taxFromRow(row: TaxRow): TaxDraft {
    return {
        party: row.party,
        gstin: row.gstin ?? '',
        state: row.state ?? '',
        invoiceNo: row.invoiceNo ?? '',
        invoiceDate: row.invoiceDate ?? '',
        hsnSac: row.hsnSac ?? '',
        rateBps: row.rateBps,
        taxable: row.taxablePaise,
        gst: row.reverseCharge
            ? null
            : row.cgstPaise + row.sgstPaise + row.igstPaise,
        itc: row.itc,
        tdsSection: row.tdsSection ?? '',
        tds: row.tdsSection ? row.tdsPaise : null,
        pan: '',
        taxableTouched: true,
        gstTouched: true,
        tdsTouched: true,
    };
}

/**
 * An expense's tax kept in step with its amount: the taxable value backs out of the total
 * (unless typed), the GST is the rest, and TDS follows the section's rate (unless typed).
 * An import is reverse charge: the bill is the taxable value.
 */
export function syncExpenseTax(
    tax: TaxDraft,
    amount: number,
    options: TaxOptions,
): TaxDraft {
    const supply = supplyOf(tax, options, true);
    const charged = tax.party === 'registered' && supply !== 'import';
    const taxable =
        supply === 'import' || !charged
            ? amount
            : tax.taxableTouched && tax.taxable !== null
              ? tax.taxable
              : taxableFromTotal(amount, tax.rateBps);
    const gst = charged ? amount - taxable : 0;
    const tds =
        tax.tdsSection === ''
            ? null
            : tax.tdsTouched && tax.tds !== null
              ? tax.tds
              : tdsFor(taxable, tax.tdsSection, options);

    return { ...tax, taxable, gst, tds };
}

/** Income's tax: the GST charged at the rate, the TDS at the section's rate (unless typed). */
export function syncIncomeTax(tax: TaxDraft, options: TaxOptions): TaxDraft {
    const taxable = tax.taxable ?? 0;
    const supply = supplyOf(tax, options, false);
    const gst = options.registered ? gstFor(taxable, tax.rateBps, supply) : 0;
    const tds =
        tax.tdsSection === ''
            ? null
            : tax.tdsTouched && tax.tds !== null
              ? tax.tds
              : tdsFor(taxable, tax.tdsSection, options);

    return { ...tax, gst, tds };
}

/** What the payers pay on an expense: the bill less TDS held back. */
export function payableAfterTds(amount: number, tax: TaxDraft | null): number {
    return amount - (tax && tax.tdsSection !== '' ? (tax.tds ?? 0) : 0);
}

/** What reaches the company on income: taxable + GST − TDS. */
export function receivedAfterTax(tax: TaxDraft): number {
    return (
        (tax.taxable ?? 0) +
        (tax.gst ?? 0) -
        (tax.tdsSection !== '' ? (tax.tds ?? 0) : 0)
    );
}

export function taxPayload(tax: TaxDraft): Record<string, FormDataConvertible> {
    return {
        party: tax.party,
        gstin: tax.party === 'registered' ? tax.gstin.trim() : null,
        state: tax.party === 'unregistered' ? tax.state || null : null,
        invoice_no: tax.invoiceNo.trim() || null,
        invoice_date: tax.invoiceDate || null,
        hsn_sac: tax.hsnSac.trim() || null,
        rate_bps: tax.rateBps,
        taxable_paise: tax.taxable ?? 0,
        gst_paise: tax.gst ?? 0,
        itc: tax.itc,
        tds_section: tax.tdsSection || null,
        tds_paise: tax.tdsSection ? (tax.tds ?? 0) : 0,
        deductee_pan: tax.pan.trim() || null,
    };
}

export const PARTY_LABELS: Record<TaxParty, string> = {
    registered: 'GST registered',
    unregistered: 'Not registered',
    foreign: 'Abroad',
};

/** The first message from the tax fields (`tax.gstin`…). */
export function taxError(
    errors: Record<string, string>,
    field: string,
): string | undefined {
    return errors[`tax.${field}`];
}
