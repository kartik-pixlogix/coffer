<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TdsSection;
use App\Ledger\FiscalYears;
use App\Models\GstPayment;
use App\Models\MoneyAccount;
use App\Models\TdsChallan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Reports\Exports\Xlsx;
use App\Services\Tax\GstStates;
use App\Services\Tax\TaxPayments;
use App\Services\Tax\TaxReturns;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Tax page (D164): GSTR-1, GSTR-3B and TDS for a month, with "Record GST payment" (set-off
 * and cash) and "Record TDS deposit" (challan) for roles with "Manage tax". Exports to Excel
 * and JSON for the CA. No design board covers it (v2): it uses the Statements look.
 */
final class TaxReturnsController extends Controller
{
    public function index(Request $request, CurrentCompany $current, TaxReturns $returns, TaxPayments $payments): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $month = $this->month($request);
        $end = $month->endOfMonth()->startOfDay();
        $fyStart = FiscalYears::startOf($company, $month);

        $months = [];
        for ($m = $fyStart; $m->lte($fyStart->addYear()->subMonth()); $m = $m->addMonth()) {
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return Inertia::render('tax/index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $months,
            'profile' => [
                'registration' => $company->gst_registration->label(),
                'registered' => $company->gst_registration->isRegular(),
                'gstin' => $company->gstin,
                'state' => GstStates::name($company->state_code),
                'tanSet' => $company->tan !== null,
            ],
            'gstr1' => $returns->gstr1($company, $month),
            'gstr3b' => $returns->gstr3b($company, $month),
            'tds' => $returns->tds($company, $month, $payments),
            'gstPreview' => $payments->gstPreview($company, $end),
            'tdsOwed' => $payments->balances($company, $end)['tdsPayable'],
            'gstPayments' => GstPayment::query()->where('period', $month->toDateString())->with('voucherEntry:id,voucher_no')->orderBy('paid_on')->get()
                ->map(fn (GstPayment $p) => ['id' => $p->id, 'paidOn' => $p->paid_on->toDateString(), 'reference' => $p->reference, 'cashPaise' => $p->cash_paise, 'voucher' => $p->voucher()])->values(),
            'accounts' => MoneyAccount::query()->whereNull('archived_at')->orderBy('position')->orderBy('id')->get()
                ->map(fn (MoneyAccount $a) => ['value' => (string) $a->id, 'label' => $a->name])->values(),
            'can' => ['manage' => $user->can('action.tax.manage')],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function storeGst(Request $request, CurrentCompany $current, TaxPayments $payments): RedirectResponse
    {
        $this->authorizeManage($request);
        $company = $current->require();
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $company->id)->whereNull('archived_at')],
            'reference' => ['nullable', 'string', 'max:32'],
        ], ['money_account_id.required' => 'Pick the account it was paid from.']);

        /** @var User $user */
        $user = $request->user();
        $payment = $payments->payGst($company, CarbonImmutable::createFromFormat('!Y-m', $data['period']) ?: CarbonImmutable::today(),
            CarbonImmutable::parse($data['paid_on']), MoneyAccount::query()->findOrFail((int) $data['money_account_id']), $data['reference'] ?? null, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'GST for '.$payment->period->format('F Y').' recorded']);

        return back();
    }

    public function storeTds(Request $request, CurrentCompany $current, TaxPayments $payments): RedirectResponse
    {
        $this->authorizeManage($request);
        $company = $current->require();
        $codes = array_values(array_unique(array_map(fn (TdsSection $s) => $s->code(), TdsSection::cases())));
        $data = $request->validate([
            'section' => ['required', Rule::in($codes)],
            'period' => ['required', 'date_format:Y-m'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount_paise' => ['required', 'integer', 'min:1'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $company->id)->whereNull('archived_at')],
            'bsr_code' => ['nullable', 'regex:/^\d{7}$/'],
            'challan_no' => ['nullable', 'regex:/^\d{1,8}$/'],
        ], [
            'bsr_code.regex' => 'A BSR code is 7 digits.',
            'challan_no.regex' => 'The challan number is up to 8 digits.',
            'money_account_id.required' => 'Pick the account it was paid from.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $challan = $payments->payTds($company, $data['section'], CarbonImmutable::createFromFormat('!Y-m', $data['period']) ?: CarbonImmutable::today(),
            CarbonImmutable::parse($data['paid_on']), (int) $data['amount_paise'], MoneyAccount::query()->findOrFail((int) $data['money_account_id']),
            $data['bsr_code'] ?? null, $data['challan_no'] ?? null, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "TDS {$challan->section} deposit recorded"]);

        return back();
    }

    public function destroyGst(Request $request, GstPayment $gstPayment, CurrentCompany $current, TaxPayments $payments): RedirectResponse
    {
        $this->authorizeManage($request);
        /** @var User $user */
        $user = $request->user();
        $payments->delete($current->require(), $gstPayment, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'GST payment deleted; its entry was reversed']);

        return back();
    }

    public function destroyTds(Request $request, TdsChallan $tdsChallan, CurrentCompany $current, TaxPayments $payments): RedirectResponse
    {
        $this->authorizeManage($request);
        /** @var User $user */
        $user = $request->user();
        $payments->delete($current->require(), $tdsChallan, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'TDS deposit deleted; its entry was reversed']);

        return back();
    }

    public function export(Request $request, CurrentCompany $current, TaxReturns $returns, TaxPayments $payments, Xlsx $xlsx, AuditTrail $audit): StreamedResponse|BinaryFileResponse
    {
        $format = $request->validate(['format' => ['required', 'in:xlsx,json']])['format'];
        $company = $current->require();
        $month = $this->month($request);
        $data = [
            'company' => $company->name,
            'gstin' => $company->gstin,
            'period' => $month->format('Y-m'),
            'note' => 'Prepared by Coffer for review by your CA. Not a GSTN or TRACES upload file.',
            'gstr1' => $returns->gstr1($company, $month),
            'gstr3b' => $returns->gstr3b($company, $month),
            'tds' => $returns->tds($company, $month, $payments),
        ];
        $name = str($company->name)->slug().'-tax-'.$month->format('Y-m').'.'.$format;
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        $audit->record($company->id, 'exported', label: 'Tax returns '.$month->format('M Y').' ('.strtoupper($format).')');

        if ($format === 'json') {
            $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return response()->streamDownload(function () use ($json): void {
                echo $json;
            }, $name, ['Content-Type' => 'application/json', ...$headers]);
        }

        $m = fn (mixed $paise): array => Xlsx::money((int) $paise);
        $t = fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $invoice = fn (array $r): array => [$t($r['date']), $t($r['invoiceNo']), $t($r['party']), $t($r['gstin']), $t($r['stateName']), $t($r['ratePct']), $m($r['taxable']), $m($r['igst']), $m($r['cgst']), $m($r['sgst'])];
        $head = ['Date', 'Invoice', 'Party', 'GSTIN', 'Place of supply', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST'];
        $three = $data['gstr3b'];
        $fig = fn (string $label, array $f): array => [$label, $m($f['taxable']), $m($f['igst']), $m($f['cgst']), $m($f['sgst'])];

        return response()->download($xlsx->write([
            ['name' => 'GSTR-1 B2B', 'header' => $head, 'rows' => array_map($invoice, $data['gstr1']['b2b'])],
            ['name' => 'GSTR-1 B2C', 'header' => ['Place of supply', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST'],
                'rows' => array_map(fn (array $r): array => [$t($r['stateName']), $t($r['ratePct']), $m($r['taxable']), $m($r['igst']), $m($r['cgst']), $m($r['sgst'])], $data['gstr1']['b2c'])],
            ['name' => 'GSTR-1 Exports', 'header' => $head, 'rows' => array_map($invoice, $data['gstr1']['exports'])],
            ['name' => 'GSTR-1 HSN', 'header' => ['HSN/SAC', 'Description', 'Documents', 'Taxable', 'IGST', 'CGST', 'SGST'],
                'rows' => array_map(fn (array $r): array => [$t($r['code']), $t($r['description']), (int) $r['documents'], $m($r['taxable']), $m($r['igst']), $m($r['cgst']), $m($r['sgst'])], $data['gstr1']['hsn'])],
            ['name' => 'GSTR-3B', 'header' => ['Table', 'Taxable', 'IGST', 'CGST', 'SGST'], 'rows' => [
                $fig('3.1(a) Outward taxable supplies', $three['outwardTaxable']),
                $fig('3.1(b) Zero-rated (exports)', $three['zeroRated']),
                $fig('3.1(d) Inward supplies under reverse charge', $three['inwardRcm']),
                $fig('4(A)(2) ITC: import of services', $three['itcImport']),
                $fig('4(A)(3) ITC: inward reverse charge', $three['itcRcmOther']),
                $fig('4(A)(5) ITC: all other', $three['itcOther']),
                $fig('4(D) Ineligible ITC', $three['itcIneligible']),
                $fig('4(C) Net ITC available', $three['itcNet']),
            ]],
            ['name' => 'TDS', 'header' => ['Section', 'Deducted', 'Deposited', 'Owed at month end'],
                'rows' => array_values(array_map(fn (array $r): array => [$t($r['section']), $m($r['deducted']), $m($r['deposited']), $m($r['owed'])], $data['tds']['sections']))],
            ['name' => 'TDS deductees', 'header' => ['Date', 'Party', 'PAN', 'Section', 'Taxable', 'TDS'],
                'rows' => array_values(array_map(fn (array $r): array => [$t($r['date']), $t($r['party']), $t($r['pan']), $t($r['section']), $m($r['taxable']), $m($r['tds'])], $data['tds']['deductees']))],
        ]), $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...$headers])->deleteFileAfterSend();
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = $request->query('month');
        $month = is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $value) : null;

        return ($month instanceof CarbonImmutable ? $month : CarbonImmutable::today()->subMonthNoOverflow())->startOfMonth();
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can('action.tax.manage') ?? false, 403);
    }
}
