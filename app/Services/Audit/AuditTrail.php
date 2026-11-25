<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use App\Services\Platform\Impersonator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail (D153, Companies (Accounts) Rules 3(1) "edit log"): every create,
 * edit, reversal, approval and settings change on money records, with who, when, from
 * where and each field before and after. It can't be switched off: there is no setting,
 * and it writes inside the caller's transaction, so a change whose audit row can't be
 * written is rolled back with it.
 *
 * Appends lock the company's `audit_heads` row, so each company's chain is strictly serial,
 * and each row carries the previous row's hash (AuditChain).
 */
final class AuditTrail
{
    public function __construct(private readonly AuditChain $chain) {}

    /**
     * @param  array<string, array{from?: mixed, to?: mixed, changed?: bool}|mixed>  $changes  field → {from, to}, or any detail worth keeping
     */
    public function record(int $companyId, string $action, ?Model $subject = null, array $changes = [], ?string $label = null, ?string $voucher = null): void
    {
        $context = $this->context();

        DB::transaction(function () use ($companyId, $action, $subject, $changes, $label, $voucher, $context): void {
            DB::table('audit_heads')->insertOrIgnore(['company_id' => $companyId, 'seq' => 0, 'hash' => AuditChain::GENESIS]);
            $head = DB::table('audit_heads')->where('company_id', $companyId)->lockForUpdate()->first();

            $row = [
                'company_id' => $companyId,
                'seq' => (int) ($head->seq ?? 0) + 1,
                'occurred_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
                ...$context,
                'action' => mb_substr($action, 0, 32),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label === null ? null : mb_substr($label, 0, 255),
                'voucher_no' => $voucher,
                'changes' => (string) json_encode((object) $changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'prev_hash' => (string) ($head->hash ?? AuditChain::GENESIS),
            ];
            $row['hash'] = $this->chain->hash($row);

            DB::table('audit_events')->insert($row);
            DB::table('audit_heads')->where('company_id', $companyId)->update(['seq' => $row['seq'], 'hash' => $row['hash']]);
        });
    }

    /** @return array{user_id: int|null, actor: string, impersonator: string|null, ip: string|null, user_agent: string|null} */
    private function context(): array
    {
        $user = Auth::guard('web')->user();
        // A routed request is a web action; console commands and queued jobs have none.
        $http = app()->bound('request') && request()->route() !== null;
        $impersonation = $http && request()->hasSession() ? request()->session()->get(Impersonator::SESSION) : null;

        return [
            'user_id' => $user instanceof User ? $user->id : null,
            'actor' => $user instanceof User ? 'user' : ($http ? 'system' : 'console'),
            'impersonator' => is_array($impersonation) && isset($impersonation['admin_name']) ? mb_substr((string) $impersonation['admin_name'], 0, 191) : null,
            'ip' => $http ? request()->ip() : null,
            'user_agent' => $http ? (mb_substr((string) request()->userAgent(), 0, 255) ?: null) : null,
        ];
    }
}
