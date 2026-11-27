import { Head, Link, router } from '@inertiajs/react';
import { Check, Landmark, Receipt, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ExportMenu } from '@/components/reports/statement-parts';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DatePicker } from '@/components/ui/date-picker';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { TableWrap } from '@/components/ui/table-wrap';
import { Tab, TabPanel, Tabs, TabsList } from '@/components/ui/tabs';
import { TextInput } from '@/components/ui/text-input';
import { formatDate, formatInr } from '@/lib/format';
import { tax as taxSettings } from '@/routes/settings';
import { exportMethod as exportTax, index as taxIndex } from '@/routes/tax';
import {
    destroy as deleteGst,
    store as storeGst,
} from '@/routes/tax/gst-payments';
import {
    destroy as deleteTds,
    store as storeTds,
} from '@/routes/tax/tds-challans';

type Heads = { igst: number; cgst: number; sgst: number };
type Figures = Heads & { taxable: number };
type InvoiceLine = Figures & {
    date: string;
    invoiceNo: string | null;
    party: string;
    gstin: string | null;
    stateName: string | null;
    ratePct: number;
};
type Option = { value: string; label: string };

type Props = {
    month: string;
    monthLabel: string;
    months: Option[];
    profile: {
        registration: string;
        registered: boolean;
        gstin: string | null;
        state: string | null;
        tanSet: boolean;
    };
    gstr1: {
        b2b: InvoiceLine[];
        b2c: (Figures & { stateName: string | null; ratePct: number })[];
        exports: InvoiceLine[];
        hsn: (Figures & {
            code: string;
            description: string | null;
            documents: number;
        })[];
        totals: Figures;
    };
    gstr3b: {
        outwardTaxable: Figures;
        zeroRated: Figures;
        inwardRcm: Figures;
        itcImport: Figures;
        itcRcmOther: Figures;
        itcOther: Figures;
        itcIneligible: Figures;
        itcNet: Figures;
        payment: {
            paidOn: string;
            reference: string | null;
            cashPaise: number;
        } | null;
    };
    tds: {
        sections: {
            section: string;
            deducted: number;
            deposited: number;
            owed: number;
        }[];
        deductees: {
            date: string;
            party: string;
            pan: string | null;
            section: string;
            taxable: number;
            tds: number;
        }[];
        receivable: {
            date: string;
            party: string;
            section: string | null;
            taxable: number;
            tds: number;
        }[];
        challans: {
            id: number;
            section: string;
            amount: number;
            paidOn: string;
            bsr: string | null;
            challan: string | null;
        }[];
    };
    gstPreview: {
        output: Heads;
        rcm: Heads;
        credit: Heads;
        settle: {
            usedByCredit: Heads;
            cash: Heads;
            creditLeft: Heads;
            cashTotal: number;
        };
    };
    tdsOwed: Record<string, number>;
    gstPayments: {
        id: number;
        paidOn: string;
        reference: string | null;
        cashPaise: number;
        voucher: string | null;
    }[];
    accounts: Option[];
    can: { manage: boolean };
    today: string;
};

const fig = (paise: number) => (paise === 0 ? '—' : formatInr(paise));
const HEADS: (keyof Heads)[] = ['igst', 'cgst', 'sgst'];

/** A table of invoices (GSTR-1 B2B and exports). */
function InvoiceTable({ rows, label }: { rows: InvoiceLine[]; label: string }) {
    return (
        <TableWrap label={label}>
            <table className="s3-table tx-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Invoice</th>
                        <th scope="col">Party</th>
                        <th scope="col">GSTIN</th>
                        <th scope="col">Place of supply</th>
                        <th scope="col" className="s3-num">
                            Taxable
                        </th>
                        <th scope="col" className="s3-num">
                            IGST
                        </th>
                        <th scope="col" className="s3-num">
                            CGST
                        </th>
                        <th scope="col" className="s3-num">
                            SGST
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={9} className="t-muted">
                                None this month.
                            </td>
                        </tr>
                    )}
                    {rows.map((r, i) => (
                        <tr key={`${r.invoiceNo}-${i}`}>
                            <td>{formatDate(r.date)}</td>
                            <td className="t-money-sm">{r.invoiceNo ?? '—'}</td>
                            <td>{r.party}</td>
                            <td className="sm-code">{r.gstin ?? '—'}</td>
                            <td>{r.stateName ?? '—'}</td>
                            <td className="s3-num t-money-sm">
                                {fig(r.taxable)}
                            </td>
                            <td className="s3-num t-money-sm">{fig(r.igst)}</td>
                            <td className="s3-num t-money-sm">{fig(r.cgst)}</td>
                            <td className="s3-num t-money-sm">{fig(r.sgst)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableWrap>
    );
}

/** One GSTR-3B row. */
function Row3b({ label, f }: { label: string; f: Figures }) {
    return (
        <tr>
            <th scope="row">{label}</th>
            <td className="s3-num t-money-sm">{fig(f.taxable)}</td>
            <td className="s3-num t-money-sm">{fig(f.igst)}</td>
            <td className="s3-num t-money-sm">{fig(f.cgst)}</td>
            <td className="s3-num t-money-sm">{fig(f.sgst)}</td>
        </tr>
    );
}

/**
 * GST and TDS for a month (D164): GSTR-1 (sales), GSTR-3B (the summary with input credit and
 * the set-off) and TDS (deducted, deposited, owed). Roles with "Manage tax" record the GST
 * payment and TDS deposits here. Figures are for your CA, not a GSTN upload file.
 */
export default function TaxPage(props: Props) {
    const { month, months, profile, gstr1, gstr3b, tds, gstPreview, can } =
        props;
    const [gstOpen, setGstOpen] = useState(false);
    const [tdsFor, setTdsFor] = useState<string | null>(null);
    const [removing, setRemoving] = useState<{
        kind: 'gst' | 'tds';
        id: number;
        label: string;
    } | null>(null);
    const go = (next: string) =>
        router.get(
            taxIndex().url,
            { month: next },
            { preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Tax" />
            <PageHeader
                kicker="Statements"
                title="Tax"
                crumbs={[{ title: 'Statements' }, { title: 'Tax' }]}
                actions={
                    <>
                        <Select
                            pill
                            aria-label="Month"
                            value={month}
                            options={months}
                            onChange={(e) => go(e.target.value)}
                        />
                        <ExportMenu
                            formats={['xlsx', 'json']}
                            exportUrl={(format) =>
                                exportTax({ query: { month, format } }).url
                            }
                        />
                    </>
                }
            />

            {profile.gstin === null || !profile.tanSet ? (
                <AlertBanner
                    variant="warn"
                    title="Tax details are incomplete"
                    action={
                        <Link
                            className="cf-btn cf-btn--secondary"
                            href={taxSettings().url}
                        >
                            Settings › Tax
                        </Link>
                    }
                >
                    {profile.gstin === null ? 'No GSTIN is set. ' : ''}
                    {!profile.tanSet ? 'No TAN is set. ' : ''}
                    Add them so these figures match what you file.
                </AlertBanner>
            ) : (
                <p className="t-caption t-muted m-0">
                    {profile.registration} · GSTIN {profile.gstin}
                    {profile.state ? ` · ${profile.state}` : ''}. Figures are
                    prepared for your CA to review; they aren’t a GSTN upload
                    file.
                </p>
            )}

            <Tabs defaultValue="gstr1">
                <TabsList aria-label="Returns">
                    <Tab value="gstr1">GSTR-1</Tab>
                    <Tab value="gstr3b">GSTR-3B</Tab>
                    <Tab value="tds" count={tds.sections.length}>
                        TDS
                    </Tab>
                </TabsList>

                <TabPanel value="gstr1" className="cf-stack tx-panel">
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="b2b-h"
                    >
                        <div className="cf-kv">
                            <h2 id="b2b-h" className="cf-dot-title t-title">
                                B2B invoices
                            </h2>
                            <span className="t-caption t-muted">
                                Total tax{' '}
                                {formatInr(
                                    gstr1.totals.igst +
                                        gstr1.totals.cgst +
                                        gstr1.totals.sgst,
                                )}{' '}
                                on {formatInr(gstr1.totals.taxable)} taxable
                            </span>
                        </div>
                        <InvoiceTable rows={gstr1.b2b} label="B2B invoices" />
                    </section>
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="b2c-h"
                    >
                        <h2 id="b2c-h" className="cf-dot-title t-title">
                            B2C (unregistered buyers), by state and rate
                        </h2>
                        <TableWrap label="B2C">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Place of supply</th>
                                        <th scope="col" className="s3-num">
                                            Rate
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Taxable
                                        </th>
                                        <th scope="col" className="s3-num">
                                            IGST
                                        </th>
                                        <th scope="col" className="s3-num">
                                            CGST
                                        </th>
                                        <th scope="col" className="s3-num">
                                            SGST
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gstr1.b2c.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="t-muted">
                                                None this month.
                                            </td>
                                        </tr>
                                    )}
                                    {gstr1.b2c.map((r, i) => (
                                        <tr key={i}>
                                            <td>{r.stateName ?? '—'}</td>
                                            <td className="s3-num">
                                                {r.ratePct}%
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.taxable)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.igst)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.cgst)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.sgst)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    </section>
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="exp-h"
                    >
                        <h2 id="exp-h" className="cf-dot-title t-title">
                            Exports (zero-rated)
                        </h2>
                        <InvoiceTable rows={gstr1.exports} label="Exports" />
                    </section>
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="hsn-h"
                    >
                        <h2 id="hsn-h" className="cf-dot-title t-title">
                            HSN / SAC summary
                        </h2>
                        <TableWrap label="HSN summary">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Code</th>
                                        <th scope="col">Description</th>
                                        <th scope="col" className="s3-num">
                                            Invoices
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Taxable
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Tax
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gstr1.hsn.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="t-muted">
                                                None this month.
                                            </td>
                                        </tr>
                                    )}
                                    {gstr1.hsn.map((r) => (
                                        <tr key={r.code}>
                                            <td className="sm-code">
                                                {r.code}
                                            </td>
                                            <td>{r.description ?? '—'}</td>
                                            <td className="s3-num">
                                                {r.documents}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.taxable)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.igst + r.cgst + r.sgst)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    </section>
                </TabPanel>

                <TabPanel value="gstr3b" className="cf-stack tx-panel">
                    <section className="cf-card sm-card" aria-labelledby="g3-h">
                        <h2 id="g3-h" className="cf-dot-title t-title">
                            GSTR-3B summary
                        </h2>
                        <TableWrap label="GSTR-3B">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Table</th>
                                        <th scope="col" className="s3-num">
                                            Taxable
                                        </th>
                                        <th scope="col" className="s3-num">
                                            IGST
                                        </th>
                                        <th scope="col" className="s3-num">
                                            CGST
                                        </th>
                                        <th scope="col" className="s3-num">
                                            SGST
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <Row3b
                                        label="3.1(a) Outward taxable supplies"
                                        f={gstr3b.outwardTaxable}
                                    />
                                    <Row3b
                                        label="3.1(b) Zero-rated (exports)"
                                        f={gstr3b.zeroRated}
                                    />
                                    <Row3b
                                        label="3.1(d) Inward supplies, reverse charge"
                                        f={gstr3b.inwardRcm}
                                    />
                                    <Row3b
                                        label="4(A)(2) ITC: import of services"
                                        f={gstr3b.itcImport}
                                    />
                                    <Row3b
                                        label="4(A)(3) ITC: inward reverse charge"
                                        f={gstr3b.itcRcmOther}
                                    />
                                    <Row3b
                                        label="4(A)(5) ITC: all other"
                                        f={gstr3b.itcOther}
                                    />
                                    <Row3b
                                        label="4(D) Ineligible ITC"
                                        f={gstr3b.itcIneligible}
                                    />
                                    <tr className="s3-strong">
                                        <th scope="row">
                                            4(C) Net ITC available
                                        </th>
                                        <td className="s3-num t-money-sm">
                                            {fig(gstr3b.itcNet.taxable)}
                                        </td>
                                        <td className="s3-num t-money-sm">
                                            {fig(gstr3b.itcNet.igst)}
                                        </td>
                                        <td className="s3-num t-money-sm">
                                            {fig(gstr3b.itcNet.cgst)}
                                        </td>
                                        <td className="s3-num t-money-sm">
                                            {fig(gstr3b.itcNet.sgst)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </TableWrap>
                    </section>

                    <section
                        className="cf-card sm-card"
                        aria-labelledby="pay-h"
                    >
                        <div className="cf-kv">
                            <h2 id="pay-h" className="cf-dot-title t-title">
                                Set-off and payment up to {props.monthLabel}
                            </h2>
                            {can.manage && (
                                <Button
                                    variant="primary"
                                    icon={Landmark}
                                    disabled={
                                        gstPreview.settle.cashTotal === 0 &&
                                        HEADS.every(
                                            (h) =>
                                                gstPreview.settle.usedByCredit[
                                                    h
                                                ] === 0,
                                        )
                                    }
                                    onClick={() => setGstOpen(true)}
                                >
                                    Record GST payment
                                </Button>
                            )}
                        </div>
                        <TableWrap label="Set-off">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Head</th>
                                        <th scope="col" className="s3-num">
                                            Output tax owed
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Reverse charge owed
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Credit used
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Cash to pay
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Credit carried forward
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {HEADS.map((h) => (
                                        <tr key={h}>
                                            <th scope="row">
                                                {h.toUpperCase()}
                                            </th>
                                            <td className="s3-num t-money-sm">
                                                {fig(gstPreview.output[h])}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(gstPreview.rcm[h])}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(
                                                    gstPreview.settle
                                                        .usedByCredit[h],
                                                )}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(gstPreview.settle.cash[h])}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(
                                                    gstPreview.settle
                                                        .creditLeft[h],
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="s3-strong">
                                        <th scope="row">Total cash</th>
                                        <td colSpan={3} />
                                        <td className="s3-num t-money-sm">
                                            {fig(gstPreview.settle.cashTotal)}
                                        </td>
                                        <td />
                                    </tr>
                                </tbody>
                            </table>
                        </TableWrap>
                        <p className="t-caption t-muted m-0">
                            IGST credit pays IGST, then CGST, then SGST; CGST
                            and SGST credit pay their own head, then IGST, never
                            each other. Reverse-charge GST is always paid in
                            cash.
                        </p>
                        {props.gstPayments.map((p) => (
                            <div key={p.id} className="cf-inset cf-row tx-paid">
                                <span className="t-body">
                                    Paid {formatDate(p.paidOn)}:{' '}
                                    {formatInr(p.cashPaise)} cash
                                    {p.reference ? ` · ${p.reference}` : ''}
                                    {p.voucher ? ` · ${p.voucher}` : ''}
                                </span>
                                {can.manage && (
                                    <IconButton
                                        icon={Trash2}
                                        size="sm"
                                        aria-label={`Delete the GST payment of ${formatDate(p.paidOn)}`}
                                        onClick={() =>
                                            setRemoving({
                                                kind: 'gst',
                                                id: p.id,
                                                label: `the GST payment of ${formatDate(p.paidOn)}`,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </section>
                </TabPanel>

                <TabPanel value="tds" className="cf-stack tx-panel">
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="tds-h"
                    >
                        <h2 id="tds-h" className="cf-dot-title t-title">
                            TDS by section
                        </h2>
                        <TableWrap label="TDS by section">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Section</th>
                                        <th scope="col" className="s3-num">
                                            Deducted
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Deposited
                                        </th>
                                        <th scope="col" className="s3-num">
                                            Owed at month end
                                        </th>
                                        <th scope="col">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tds.sections.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="t-muted">
                                                No TDS this month.
                                            </td>
                                        </tr>
                                    )}
                                    {tds.sections.map((s) => (
                                        <tr key={s.section}>
                                            <th scope="row">{s.section}</th>
                                            <td className="s3-num t-money-sm">
                                                {fig(s.deducted)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(s.deposited)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(s.owed)}
                                            </td>
                                            <td>
                                                {can.manage && s.owed > 0 && (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            setTdsFor(s.section)
                                                        }
                                                    >
                                                        Record deposit
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                        {tds.challans.map((c) => (
                            <div key={c.id} className="cf-inset cf-row tx-paid">
                                <span className="t-body">
                                    {c.section} deposited {formatDate(c.paidOn)}
                                    : {formatInr(c.amount)}
                                    {c.challan ? ` · challan ${c.challan}` : ''}
                                    {c.bsr ? ` · BSR ${c.bsr}` : ''}
                                </span>
                                {can.manage && (
                                    <IconButton
                                        icon={Trash2}
                                        size="sm"
                                        aria-label={`Delete the ${c.section} deposit of ${formatDate(c.paidOn)}`}
                                        onClick={() =>
                                            setRemoving({
                                                kind: 'tds',
                                                id: c.id,
                                                label: `the ${c.section} deposit of ${formatDate(c.paidOn)}`,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </section>
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="ded-h"
                    >
                        <h2 id="ded-h" className="cf-dot-title t-title">
                            Deductees
                        </h2>
                        <TableWrap label="Deductees">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Bill</th>
                                        <th scope="col">PAN</th>
                                        <th scope="col">Section</th>
                                        <th scope="col" className="s3-num">
                                            Taxable
                                        </th>
                                        <th scope="col" className="s3-num">
                                            TDS
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tds.deductees.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="t-muted">
                                                None this month.
                                            </td>
                                        </tr>
                                    )}
                                    {tds.deductees.map((d, i) => (
                                        <tr key={i}>
                                            <td>{formatDate(d.date)}</td>
                                            <td>{d.party}</td>
                                            <td className="sm-code">
                                                {d.pan ?? '—'}
                                            </td>
                                            <td>{d.section}</td>
                                            <td className="s3-num t-money-sm">
                                                {fig(d.taxable)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(d.tds)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    </section>
                    <section
                        className="cf-card sm-card"
                        aria-labelledby="rec-h"
                    >
                        <h2 id="rec-h" className="cf-dot-title t-title">
                            TDS clients deducted from us
                        </h2>
                        <TableWrap label="TDS receivable">
                            <table className="s3-table tx-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Client</th>
                                        <th scope="col">Section</th>
                                        <th scope="col" className="s3-num">
                                            Taxable
                                        </th>
                                        <th scope="col" className="s3-num">
                                            TDS
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tds.receivable.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="t-muted">
                                                None this month.
                                            </td>
                                        </tr>
                                    )}
                                    {tds.receivable.map((r, i) => (
                                        <tr key={i}>
                                            <td>{formatDate(r.date)}</td>
                                            <td>{r.party}</td>
                                            <td>{r.section ?? '—'}</td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.taxable)}
                                            </td>
                                            <td className="s3-num t-money-sm">
                                                {fig(r.tds)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    </section>
                </TabPanel>
            </Tabs>

            {gstOpen && (
                <GstPaymentModal {...props} onClose={() => setGstOpen(false)} />
            )}
            {tdsFor !== null && (
                <TdsDepositModal
                    {...props}
                    section={tdsFor}
                    onClose={() => setTdsFor(null)}
                />
            )}
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                icon={Trash2}
                title={`Delete ${removing?.label ?? 'this payment'}?`}
                description="Its journal entry is reversed; the books keep both. Record it again if it was wrong."
                confirmLabel="Delete"
                onConfirm={() => {
                    if (removing === null) {
                        return;
                    }

                    const url =
                        removing.kind === 'gst'
                            ? deleteGst(removing.id).url
                            : deleteTds(removing.id).url;
                    router.delete(url, {
                        preserveScroll: true,
                        onFinish: () => setRemoving(null),
                    });
                }}
            />
        </>
    );
}

function GstPaymentModal({
    month,
    monthLabel,
    gstPreview,
    accounts,
    today,
    onClose,
}: Props & { onClose: () => void }) {
    const [paidOn, setPaidOn] = useState(today);
    const [account, setAccount] = useState(accounts[0]?.value ?? '');
    const [reference, setReference] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const save = () =>
        router.post(
            storeGst().url,
            {
                period: month,
                paid_on: paidOn,
                money_account_id: account,
                reference: reference.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onClose,
                onError: setErrors,
            },
        );

    return (
        <Modal
            open
            onOpenChange={(open) => !open && onClose()}
            title="Record GST payment"
            subtitle={`Everything owed up to the end of ${monthLabel}`}
            icon={Landmark}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" size="lg" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={Check}
                        loading={saving}
                        onClick={save}
                    >
                        Pay {formatInr(gstPreview.settle.cashTotal)}
                    </Button>
                </>
            }
        >
            <p className="t-body m-0">
                Credit used:{' '}
                {formatInr(
                    HEADS.reduce(
                        (s, h) => s + gstPreview.settle.usedByCredit[h],
                        0,
                    ),
                )}
                . Cash: {formatInr(gstPreview.settle.cashTotal)}.
            </p>
            <DatePicker
                label="Paid on"
                value={paidOn}
                onValueChange={(d) => d && setPaidOn(d)}
                error={errors.paid_on ?? errors.period}
            />
            <Select
                label="Paid from"
                value={account}
                options={accounts}
                onChange={(e) => setAccount(e.target.value)}
                error={errors.money_account_id}
            />
            <TextInput
                label="CPIN or CIN"
                value={reference}
                maxLength={32}
                placeholder="Optional"
                onChange={(e) => setReference(e.target.value)}
                error={errors.reference}
            />
        </Modal>
    );
}

function TdsDepositModal({
    month,
    monthLabel,
    tdsOwed,
    accounts,
    today,
    section,
    onClose,
}: Props & { section: string; onClose: () => void }) {
    const [amount, setAmount] = useState<number | null>(
        tdsOwed[section] ?? null,
    );
    const [paidOn, setPaidOn] = useState(today);
    const [account, setAccount] = useState(accounts[0]?.value ?? '');
    const [bsr, setBsr] = useState('');
    const [challan, setChallan] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const save = () =>
        router.post(
            storeTds().url,
            {
                section,
                period: month,
                paid_on: paidOn,
                amount_paise: amount ?? 0,
                money_account_id: account,
                bsr_code: bsr.trim() || null,
                challan_no: challan.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onClose,
                onError: setErrors,
            },
        );

    return (
        <Modal
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Record TDS ${section} deposit`}
            subtitle={`TDS owed up to the end of ${monthLabel}`}
            icon={Receipt}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" size="lg" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={Check}
                        loading={saving}
                        onClick={save}
                    >
                        Record deposit
                    </Button>
                </>
            }
        >
            <AmountInput
                label="Amount deposited"
                value={amount}
                onValueChange={setAmount}
                error={errors.amount_paise}
            />
            <DatePicker
                label="Paid on"
                value={paidOn}
                onValueChange={(d) => d && setPaidOn(d)}
                error={errors.paid_on}
            />
            <Select
                label="Paid from"
                value={account}
                options={accounts}
                onChange={(e) => setAccount(e.target.value)}
                error={errors.money_account_id}
            />
            <div className="wiz-grid-2">
                <TextInput
                    label="BSR code"
                    value={bsr}
                    maxLength={7}
                    inputMode="numeric"
                    placeholder="Optional"
                    onChange={(e) => setBsr(e.target.value)}
                    error={errors.bsr_code}
                />
                <TextInput
                    label="Challan number"
                    value={challan}
                    maxLength={8}
                    inputMode="numeric"
                    placeholder="Optional"
                    onChange={(e) => setChallan(e.target.value)}
                    error={errors.challan_no}
                />
            </div>
        </Modal>
    );
}
