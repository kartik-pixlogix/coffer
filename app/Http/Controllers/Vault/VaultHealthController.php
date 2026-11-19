<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAuditAction;
use App\Http\Controllers\Controller;
use App\Jobs\CheckVaultBreaches;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\HealthReport;
use App\Services\Vault\OffboardingList;
use App\Services\Vault\PwnedPasswords;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultPolicy;
use App\Services\Vault\VaultSession;
use App\Support\Csv;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Security health (VaultHealth.dc.html, spec §14.4, D74): the score over the items the
 * viewer can see, the six fix-it lists with Rotate / Renew / Add 2FA, the offboarding
 * rotate lists, "Run check again" (the optional breach check, queued) and a CSV of the
 * issues (names and categories only).
 */
final class VaultHealthController extends Controller
{
    public const array FILTERS = ['weak', 'reused', 'old', 'breached', 'expiring', 'no2fa'];

    public function index(Request $request, VaultSession $session, VaultAccess $access, HealthReport $report, OffboardingList $offboardings, CurrentCompany $current): Response
    {
        $user = $this->user($request);
        $open = $session->opensFor($user);
        $filter = $request->query('filter');

        return Inertia::render('vault/health', [
            'vaultState' => $session->pageState($user),
            'report' => $open ? $report->build($user, $access->visibleItems($user)) : null,
            'offboardings' => $open ? $offboardings->forViewer($user) : [],
            'filter' => is_string($filter) && in_array($filter, self::FILTERS, true) ? $filter : null,
            'breachChecks' => PwnedPasswords::enabled() && VaultPolicy::for($current->require())->breachChecks,
            'abilities' => ['admin' => $access->isAdmin($user)],
        ]);
    }

    /** "Run check again": queue the breach check for the items the viewer can see. */
    public function check(Request $request, VaultAccess $access, CurrentCompany $current): RedirectResponse
    {
        $company = $current->require();

        if (! PwnedPasswords::enabled() || ! VaultPolicy::for($company)->breachChecks) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Breach checks are off for this company.']);

            return back();
        }

        $ids = array_values(array_map(fn (VaultItem $i): int => $i->id, $access->visibleItems($this->user($request))->all()));
        CheckVaultBreaches::dispatch($company->id, $ids);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checking passwords against breach lists. Results show here in a minute.']);

        return back();
    }

    /** "Export report": the issues as CSV — item names, vaults and what's wrong, nothing secret. */
    public function export(Request $request, VaultAccess $access, HealthReport $report, VaultAudit $audit, CurrentCompany $current): StreamedResponse
    {
        $user = $this->user($request);
        $data = $report->build($user, $access->visibleItems($user));
        $audit->record(VaultAuditAction::Exported, $user, null, null, ['what' => 'health report', 'rows' => $data['issues']]);

        $name = 'vault-health-'.str($current->require()->name)->slug().'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Issue', 'Item', 'Vault', 'Detail']);

            foreach ($data['lists'] as $list) {
                foreach ($list['rows'] as $row) {
                    Csv::put($out, [$list['title'], $row['name'], $row['vault'], $row['detail']]);
                }
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
