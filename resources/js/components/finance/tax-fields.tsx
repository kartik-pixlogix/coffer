import { useId } from 'react';
import {
    PARTY_LABELS,
    receivedAfterTax,
    supplyOf,
    taxError,
} from '@/components/finance/tax-form';
import { AmountInput } from '@/components/ui/amount-input';
import { DatePicker } from '@/components/ui/date-picker';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { Toggle } from '@/components/ui/toggle';
import { formatInr } from '@/lib/format';
import type { TaxDraft, TaxOptions, TaxParty, TaxRow } from '@/types/tax';

const SUPPLY_TEXT = {
    intra: 'CGST + SGST (same state)',
    inter: 'IGST (another state)',
    import: 'IGST under reverse charge: the company pays it',
    export: 'Zero-rated export: no GST',
} as const;

/**
 * The "GST and TDS" part of the expense and income forms (D162). Expenses: the supplier,
 * the bill's taxable value and GST, input credit and the TDS held back. Income: the client,
 * the taxable value (GST worked out at the rate) and the TDS the client held back, with the
 * amount received worked out.
 */
export function TaxFields({
    kind,
    tax,
    options,
    errors,
    onChange,
}: {
    kind: 'expense' | 'income';
    tax: TaxDraft;
    options: TaxOptions;
    errors: Record<string, string>;
    onChange: (patch: Partial<TaxDraft>) => void;
}) {
    const id = useId();
    const inward = kind === 'expense';
    const supply = supplyOf(tax, options, inward);
    const listId = `${id}-codes`;
    const parties = (Object.keys(PARTY_LABELS) as TaxParty[]).map((p) => ({
        value: p,
        label: PARTY_LABELS[p],
    }));
    const showGst = inward
        ? tax.party === 'registered' && supply !== 'import'
        : options.registered && supply !== 'export';

    return (
        <fieldset className="tx-fields">
            <legend className="cf-label">
                {inward ? 'Supplier' : 'Client'}
            </legend>
            <SegmentedControl
                aria-label={inward ? 'Supplier' : 'Client'}
                options={parties}
                value={tax.party}
                onValueChange={(party) => onChange({ party })}
            />

            <div className="wiz-grid-2">
                {tax.party === 'registered' ? (
                    <TextInput
                        label={inward ? 'Supplier’s GSTIN' : 'Client’s GSTIN'}
                        value={tax.gstin}
                        maxLength={15}
                        className="t-mono"
                        placeholder="27ABCDE1234F1Z5"
                        onChange={(e) =>
                            onChange({ gstin: e.target.value.toUpperCase() })
                        }
                        error={taxError(errors, 'gstin')}
                    />
                ) : tax.party === 'unregistered' ? (
                    <Select
                        label="State"
                        value={tax.state}
                        options={options.states}
                        placeholder="Same state as the company"
                        onChange={(e) => onChange({ state: e.target.value })}
                        error={taxError(errors, 'state')}
                    />
                ) : (
                    <div className="cf-field">
                        <span className="cf-label">Place of supply</span>
                        <span className="t-body t-muted">
                            {inward
                                ? 'Outside India (import)'
                                : 'Outside India (export)'}
                        </span>
                    </div>
                )}
                <TextInput
                    label={inward ? 'Bill number' : 'Invoice number'}
                    value={tax.invoiceNo}
                    maxLength={32}
                    placeholder="Optional"
                    onChange={(e) => onChange({ invoiceNo: e.target.value })}
                    error={taxError(errors, 'invoice_no')}
                />
                <DatePicker
                    label={inward ? 'Bill date' : 'Invoice date'}
                    value={tax.invoiceDate || null}
                    onValueChange={(d) => onChange({ invoiceDate: d ?? '' })}
                    error={taxError(errors, 'invoice_date')}
                />
                <TextInput
                    label="HSN / SAC"
                    value={tax.hsnSac}
                    maxLength={8}
                    inputMode="numeric"
                    placeholder="998314"
                    list={listId}
                    onChange={(e) => onChange({ hsnSac: e.target.value })}
                    error={taxError(errors, 'hsn_sac')}
                />
                <datalist id={listId}>
                    {options.codes.map((c) => (
                        <option key={c.code} value={c.code}>
                            {c.label}
                        </option>
                    ))}
                </datalist>
                {(inward || options.registered) && (
                    <Select
                        label="GST rate"
                        value={String(tax.rateBps)}
                        options={options.rates.map((r) => ({
                            value: String(r.value),
                            label: r.label,
                        }))}
                        onChange={(e) =>
                            onChange({
                                rateBps: Number(e.target.value),
                                taxableTouched: false,
                            })
                        }
                        error={taxError(errors, 'rate_bps')}
                    />
                )}
                <AmountInput
                    label="Taxable value"
                    value={tax.taxable}
                    onValueChange={(taxable) =>
                        onChange({ taxable, taxableTouched: inward })
                    }
                    error={taxError(errors, 'taxable_paise')}
                />
                {showGst &&
                    (inward ? (
                        <AmountInput
                            label="GST on the bill"
                            value={tax.gst}
                            readOnly
                            help={SUPPLY_TEXT[supply]}
                            error={taxError(errors, 'gst_paise')}
                        />
                    ) : (
                        <div className="cf-field">
                            <span className="cf-label">GST charged</span>
                            <span className="t-money">
                                {formatInr(tax.gst ?? 0)}
                            </span>
                            <span className="t-caption t-muted">
                                {SUPPLY_TEXT[supply]}
                            </span>
                        </div>
                    ))}
                {inward && supply === 'import' && (
                    <div className="cf-field">
                        <span className="cf-label">Reverse charge</span>
                        <span className="t-caption t-muted">
                            {SUPPLY_TEXT.import}
                        </span>
                    </div>
                )}
            </div>

            {inward &&
                options.registered &&
                (showGst || supply === 'import') && (
                    <Toggle
                        checked={tax.itc}
                        onCheckedChange={(itc) => onChange({ itc })}
                        help="Turn off for blocked credits (food, personal use and the like)."
                    >
                        Claim input tax credit
                    </Toggle>
                )}

            <div className="wiz-grid-2">
                <Select
                    label={
                        inward ? 'TDS to hold back' : 'TDS the client held back'
                    }
                    value={tax.tdsSection}
                    options={options.tdsSections.map((s) => ({
                        value: s.value,
                        label: s.label,
                    }))}
                    placeholder="No TDS"
                    onChange={(e) =>
                        onChange({
                            tdsSection: e.target.value,
                            tdsTouched: false,
                        })
                    }
                    error={taxError(errors, 'tds_section')}
                />
                {tax.tdsSection !== '' && (
                    <AmountInput
                        label="TDS amount"
                        value={tax.tds}
                        onValueChange={(tds) =>
                            onChange({ tds, tdsTouched: true })
                        }
                        error={taxError(errors, 'tds_paise')}
                    />
                )}
                {inward &&
                    tax.tdsSection !== '' &&
                    tax.party !== 'registered' && (
                        <TextInput
                            label="Supplier’s PAN"
                            value={tax.pan}
                            maxLength={10}
                            className="t-mono"
                            placeholder="AAJCV1234K"
                            onChange={(e) =>
                                onChange({ pan: e.target.value.toUpperCase() })
                            }
                            error={taxError(errors, 'deductee_pan')}
                        />
                    )}
            </div>

            {!inward && (
                <p className="t-body m-0">
                    Amount received:{' '}
                    <span className="t-money">
                        {formatInr(receivedAfterTax(tax))}
                    </span>
                </p>
            )}
        </fieldset>
    );
}

/** A saved document's GST and TDS as rows of a facts list (the expense drawer, D162). */
export function TaxSummary({ tax }: { tax: TaxRow }) {
    const rows: [string, string][] = [
        ['Taxable value', formatInr(tax.taxablePaise)],
        ...(tax.cgstPaise > 0
            ? ([
                  ['CGST', formatInr(tax.cgstPaise)],
                  ['SGST', formatInr(tax.sgstPaise)],
              ] as [string, string][])
            : []),
        ...(tax.igstPaise > 0
            ? ([
                  [
                      tax.reverseCharge ? 'IGST (reverse charge)' : 'IGST',
                      formatInr(tax.igstPaise),
                  ],
              ] as [string, string][])
            : []),
        ...(tax.cgstPaise + tax.sgstPaise + tax.igstPaise > 0
            ? ([['Input credit', tax.itc ? 'Claimed' : 'Not claimed']] as [
                  string,
                  string,
              ][])
            : []),
        ...(tax.tdsSection
            ? ([
                  [
                      `TDS ${tax.tdsSectionLabel?.split(' · ')[0] ?? ''}`,
                      formatInr(tax.tdsPaise),
                  ],
              ] as [string, string][])
            : []),
        ...(tax.gstin ? ([['GSTIN', tax.gstin]] as [string, string][]) : []),
        ...(tax.invoiceNo
            ? ([['Bill no.', tax.invoiceNo]] as [string, string][])
            : []),
    ];

    return (
        <>
            {rows.map(([label, value]) => (
                <div key={label} className="cf-kv t-body">
                    <dt className="t-muted">{label}</dt>
                    <dd className="t-money-sm">{value}</dd>
                </div>
            ))}
        </>
    );
}
