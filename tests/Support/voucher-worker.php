<?php

declare(strict_types=1);

/*
| A separate PHP process for the voucher race test (D154), so postings really race:
|   php tests/Support/voucher-worker.php setup         → prints a new company's id
|   php tests/Support/voucher-worker.php post {id} {n} → posts n contributions
|   php tests/Support/voucher-worker.php erase {id}    → removes the company again
| The test passes the database to use in the environment. A failure exits non-zero.
*/

use App\Actions\Privacy\PurgeWorkspace;
use App\Enums\JournalType;
use App\Ledger\ChartOfAccounts;
use App\Ledger\EntryData;
use App\Ledger\LedgerPoster;
use App\Ledger\LineData;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** One ₹100 contribution from the company's partner into the company account. */
function raceEntry(Company $company, string $memo): void
{
    $chart = app(ChartOfAccounts::class);
    $partner = Partner::query()->withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

    app(LedgerPoster::class)->post($company, new EntryData(
        date: CarbonImmutable::create(2026, 5, 1),
        type: JournalType::Contribution,
        lines: [new LineData($chart->money($chart->companyAccount($company)), 100_00), new LineData($chart->capital($partner), 0, 100_00, $partner->id)],
        memo: $memo,
    ));
}

[$mode, $id, $count] = [$argv[1] ?? '', (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0)];

try {
    if ($mode === 'setup') {
        $company = Company::factory()->create(['name' => 'Voucher race']);
        Partner::factory()->for($company)->create(['name' => 'Aarav Mehta', 'equity_bps' => 10000, 'position' => 0]);
        app(ChartOfAccounts::class)->setUp($company);
        // One posting first, so the year and its voucher series exist before the race.
        raceEntry($company, 'Warm-up');
        echo $company->id;
    } elseif ($mode === 'post') {
        $company = Company::query()->findOrFail($id);

        for ($i = 0; $i < $count; $i++) {
            raceEntry($company, 'Race '.getmypid().' #'.$i);
        }
    } elseif ($mode === 'erase') {
        $company = Company::query()->findOrFail($id);
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $owner = $workspace->owner_id;
        app(PurgeWorkspace::class)->handle($workspace);
        DB::table('activity_log')->where('log_name', 'privacy')->delete();
        DB::table('users')->where('id', $owner)->delete();
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);

    exit(1);
}
