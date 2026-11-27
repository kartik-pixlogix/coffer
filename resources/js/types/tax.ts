/** GST and TDS on expenses and income (App\Services\Tax, D159–D162). */
export type TaxOption = { value: string; label: string };

/** What the forms need to work tax out: the company's registration and the rule lists. */
export type TaxOptions = {
    /** Regular-registered: charges GST and claims input credit. */
    registered: boolean;
    /** The company's GST state, "27". */
    stateCode: string | null;
    states: TaxOption[];
    rates: { value: number; label: string }[];
    tdsSections: { value: string; label: string; rateBps: number | null }[];
    /** Common SAC / HSN codes, as suggestions. */
    codes: { code: string; label: string }[];
};

export type TaxParty = 'registered' | 'unregistered' | 'foreign';

/** The "GST and TDS" part of a form. */
export type TaxDraft = {
    party: TaxParty;
    gstin: string;
    state: string;
    invoiceNo: string;
    invoiceDate: string;
    hsnSac: string;
    rateBps: number;
    taxable: number | null;
    /** Expenses: the GST on the supplier's bill. Worked out for income. */
    gst: number | null;
    /** Claim input tax credit (expenses). */
    itc: boolean;
    tdsSection: string;
    tds: number | null;
    pan: string;
    /** The person changed these by hand, so they no longer follow the amount. */
    taxableTouched: boolean;
    gstTouched: boolean;
    tdsTouched: boolean;
};

/** A saved document's tax, for editing and the drawer. */
export type TaxRow = {
    party: TaxParty;
    gstin: string | null;
    state: string | null;
    supply: 'intra' | 'inter' | 'import' | 'export' | null;
    invoiceNo: string | null;
    invoiceDate: string | null;
    hsnSac: string | null;
    rateBps: number;
    taxablePaise: number;
    cgstPaise: number;
    sgstPaise: number;
    igstPaise: number;
    reverseCharge: boolean;
    itc: boolean;
    tdsSection: string | null;
    tdsSectionLabel: string | null;
    tdsPaise: number;
};
