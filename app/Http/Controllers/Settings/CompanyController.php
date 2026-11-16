<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Companies\UpdateCompanySettings;
use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanySettingsRequest;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\FiscalYears;
use App\Ledger\Period;
use App\Ledger\SettlementCalculator;
use App\Ledger\YearEnd;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings › Company (SettingsCompany.dc.html, spec §3.2, §4.6): company details and logo,
 * money and attribution, working days, and fiscal years with Close (preview,
 * type-to-confirm, lock) and Reopen (Admin only, audited, reverses the closing entry).
 */
final class CompanyController extends Controller
{
    public const string LOGO_DISK = 'local';

    public function index(Request $request, CurrentCompany $current, FiscalYears $fiscalYears): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();

        // The current year always shows, even before anything is dated in it.
        $fiscalYears->forDate($company, CarbonImmutable::today());
        $years = FiscalYear::query()->orderByDesc('starts_on')->get();
        $closable = $years->whereNull('closed_at')->sortBy('starts_on')->first();
        $reopenable = $years->whereNotNull('closed_at')->sortByDesc('starts_on')->first();
        $closers = User::query()->whereIn('id', $years->pluck('closed_by')->filter())->pluck('name', 'id');

        return Inertia::render('settings/company', [
            'settings' => [
                'name' => $company->name,
                'legalName' => $company->legal_name,
                'gstin' => $company->gstin,
                'address' => $company->address,
                'shortCode' => $company->short_code,
                'type' => $company->type->value,
                'sides' => $company->isJv() ? $company->sides()->pluck('name')->all() : [],
                'openingBalancePaise' => $company->opening_balance_paise,
                'currency' => $company->currency,
                'attributionMode' => $company->attribution_mode->value,
                'fyStartMonth' => $company->fy_start_month,
                'workingDays' => $company->working_days,
                'hoursPerDayCenti' => $company->hours_per_day_centi,
                'logoUrl' => $company->logo_path !== null ? route('settings.company.logo', ['v' => substr(md5($company->logo_path), 0, 8)]) : null,
            ],
            'locks' => [
                'opening' => UpdateCompanySettings::anyYearClosed($company),
                'currency' => UpdateCompanySettings::hasTransactions($company),
                'fyStart' => UpdateCompanySettings::anyYearClosed($company),
            ],
            'years' => $years->map(fn (FiscalYear $y) => [
                'id' => $y->id,
                'label' => $y->label,
                'startsOn' => $y->starts_on->toDateString(),
                'endsOn' => $y->ends_on->toDateString(),
                'closed' => $y->isClosed(),
                'closedOn' => $y->closed_at?->toDateString(),
                'closedBy' => $y->closed_by !== null ? ($closers[$y->closed_by] ?? null) : null,
                'entries' => $this->entries($company, $y),
                'closable' => $closable?->id === $y->id,
                'reopenable' => $reopenable?->id === $y->id,
            ])->values(),
            'closing' => Inertia::optional(fn () => $this->preview($company, $request->integer('year'))),
            'abilities' => [
                'closeYear' => $user->can('action.fy.close'),
                'reopenYear' => $current->isAdmin(),
            ],
        ]);
    }

    public function update(CompanySettingsRequest $request, CurrentCompany $current, UpdateCompanySettings $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        $changed = $update->handle($company, $request->settings(), $user);

        activity('settings')->performedOn($company)->causedBy($user)->withProperties(['changed' => $changed])->log('updated company settings');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Company settings saved']);

        return back();
    }

    public function uploadLogo(Request $request, CurrentCompany $current): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=256,min_height=256'],
        ], [
            'logo.mimes' => 'Use a PNG, JPG or WebP image.',
            'logo.max' => 'The logo can be up to 2 MB.',
            'logo.dimensions' => 'The logo needs to be at least 256 px on each side.',
        ]);

        $company = $current->require();
        $old = $company->logo_path;
        $path = $request->file('logo')?->store('companies/'.$company->id.'/logo', self::LOGO_DISK);
        $company->forceFill(['logo_path' => $path ?: null])->save();

        if ($old !== null) {
            Storage::disk(self::LOGO_DISK)->delete($old);
        }

        activity('settings')->performedOn($company)->causedBy($request->user())->log('changed the company logo');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo updated']);

        return back();
    }

    public function removeLogo(Request $request, CurrentCompany $current): RedirectResponse
    {
        $company = $current->require();

        if ($company->logo_path !== null) {
            Storage::disk(self::LOGO_DISK)->delete($company->logo_path);
            $company->forceFill(['logo_path' => null])->save();
        }

        activity('settings')->performedOn($company)->causedBy($request->user())->log('removed the company logo');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo removed']);

        return back();
    }

    public function logo(CurrentCompany $current): StreamedResponse
    {
        $company = $current->require();
        abort_if($company->logo_path === null || ! Storage::disk(self::LOGO_DISK)->exists($company->logo_path), 404);

        return Storage::disk(self::LOGO_DISK)->response($company->logo_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function close(Request $request, FiscalYear $fiscalYear, CurrentCompany $current, YearEnd $yearEnd): RedirectResponse
    {
        Gate::authorize('action.fy.close');
        $phrase = self::phrase($fiscalYear);
        $request->validate(['confirm' => ['required', 'string']]);

        if (mb_strtoupper(trim((string) $request->input('confirm'))) !== $phrase) {
            throw ValidationException::withMessages(['confirm' => "Type {$phrase} to confirm."]);
        }

        /** @var User $user */
        $user = $request->user();
        $company = $current->require();

        try {
            $entry = $yearEnd->close($company, $fiscalYear, $user);
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['confirm' => $e->getMessage()]);
        }

        $next = FiscalYears::label($company, $fiscalYear->ends_on->addDay());
        activity('fiscal-years')->performedOn($fiscalYear)->causedBy($user)
            ->withProperties(['label' => $fiscalYear->label, 'closing_entry_id' => $entry?->id])
            ->log('closed the fiscal year');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$fiscalYear->label} closed. Balances moved to {$next}."]);

        return redirect()->route('settings.company');
    }

    public function reopen(Request $request, FiscalYear $fiscalYear, CurrentCompany $current, YearEnd $yearEnd): RedirectResponse
    {
        abort_unless($current->isAdmin(), 403);
        /** @var User $user */
        $user = $request->user();

        try {
            $yearEnd->reopen($current->require(), $fiscalYear, $user);
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['year' => $e->getMessage()]);
        }

        activity('fiscal-years')->performedOn($fiscalYear)->causedBy($user)
            ->withProperties(['label' => $fiscalYear->label])
            ->log('reopened the fiscal year');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$fiscalYear->label} reopened. Its closing entries were reversed."]);

        return redirect()->route('settings.company');
    }

    /** "CLOSE FY 2026-27" (SettingsCompany.dc.html). */
    public static function phrase(FiscalYear $year): string
    {
        return 'CLOSE '.mb_strtoupper($year->label);
    }

    /** Live entries dated in the year (corrections count once, the closing entry not at all). */
    private function entries(Company $company, FiscalYear $year): int
    {
        return JournalEntry::query()
            ->where('type', '!=', JournalType::FyClose->value)
            ->whereNull('reverses_id')
            ->whereNull('reversed_by_id')
            ->whereBetween('date', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])
            ->count();
    }

    /**
     * What closing would do (spec §4.6): net profit, each partner's share and closing capital,
     * plus what to know first (closing early, unsettled sides, pending expenses).
     *
     * @return array<string, mixed>|null
     */
    private function preview(Company $company, int $yearId): ?array
    {
        $year = FiscalYear::query()->find($yearId);

        if ($year === null || $year->isClosed()) {
            return null;
        }

        $preview = app(YearEnd::class)->preview($company, $year);
        $partners = Partner::query()->with('side:id,name')->get()->keyBy('id');
        $unsettled = 0;

        if ($company->isJv()) {
            $settlement = app(SettlementCalculator::class)->calculate($company, Period::fiscalYear($year));
            $unsettled = array_sum(array_column($settlement->transfers, 'amount'));
        }

        return [
            'yearId' => $year->id,
            'label' => $year->label,
            'phrase' => self::phrase($year),
            'startsOn' => $year->starts_on->toDateString(),
            'endsOn' => $year->ends_on->toDateString(),
            'nextLabel' => FiscalYears::label($company, $year->ends_on->addDay()),
            'netPaise' => $preview['net'],
            'partners' => array_map(fn (array $row) => [
                'id' => $row['partner_id'],
                'name' => $row['name'],
                'side' => $partners[$row['partner_id']]?->side?->name,
                'openingPaise' => $row['opening'],
                'contributionsPaise' => $row['contributions'],
                'drawingsPaise' => $row['drawings'],
                'sharePaise' => $preview['shares'][$row['partner_id']] ?? 0,
                'closingPaise' => $row['opening'] + $row['contributions'] - $row['drawings'] + ($preview['shares'][$row['partner_id']] ?? 0),
            ], $preview['capital']),
            'early' => $year->ends_on->gt(CarbonImmutable::today()),
            'unsettledPaise' => $unsettled,
            'unsettledText' => $unsettled > 0 ? Money::format($unsettled) : null,
            'pendingExpenses' => Expense::query()->where('status', ExpenseStatus::Pending->value)
                ->whereBetween('spent_on', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])->count(),
        ];
    }
}
