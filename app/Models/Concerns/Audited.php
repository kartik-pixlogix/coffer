<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Audit\AuditTrail;
use App\Services\Security\SealedFields;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts the model's creates, edits, deletes and restores in the company's audit trail (D153),
 * with each field's value before and after. Sealed fields (D149) are recorded as changed,
 * never with their values, so the trail holds no plaintext of them. Timestamps and hidden
 * attributes are left out.
 *
 * A model may define:
 * - `auditLabel(): string` how the record reads in the trail ("Expense: Figma seat");
 * - `auditAction(string $event, array $changes): ?string` a better name for an edit
 *   ("approved", "closed");
 * - `auditExcluded(): list<string>` more fields to leave out.
 *
 * Writes that skip model events (query `update()` / `delete()`, `saveQuietly`) skip the
 * trail too, so audited records are changed through their models.
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (self $model) => $model->writeAudit('created', $model->auditSnapshot(asNew: true)));
        static::updated(function (self $model): void {
            $changes = $model->auditDiff();

            if ($changes !== []) {
                $model->writeAudit('updated', $changes);
            }
        });
        static::deleted(fn (self $model) => $model->writeAudit('deleted', $model->auditSnapshot(asNew: false)));

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::registerModelEvent('restored', fn (self $model) => $model->writeAudit('restored', []));
        }
    }

    /** The company whose trail this goes in. */
    public function auditCompanyId(): ?int
    {
        $id = $this->getAttribute('company_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param array<string, array{from?: mixed, to?: mixed, changed?: bool}> $changes */
    protected function writeAudit(string $event, array $changes): void
    {
        $companyId = $this->auditCompanyId();

        if ($companyId === null) {
            return;
        }

        $action = method_exists($this, 'auditAction') ? ($this->auditAction($event, $changes) ?? $event) : $event;
        $label = method_exists($this, 'auditLabel') ? $this->auditLabel() : $this->defaultAuditLabel();

        app(AuditTrail::class)->record($companyId, $action, $this, $changes, $label, $this->auditVoucher());
    }

    /** The voucher of the record's journal entry, when it has one (D154). */
    protected function auditVoucher(): ?string
    {
        return null;
    }

    /** @return array<string, array{from?: mixed, to?: mixed, changed?: bool}> */
    protected function auditDiff(): array
    {
        $changes = [];

        foreach ($this->getChanges() as $field => $value) {
            if ($this->auditSkips($field)) {
                continue;
            }

            $changes[$field] = $this->auditSealed($field)
                ? ['changed' => true]
                : ['from' => $this->auditValue($this->getRawOriginal($field)), 'to' => $this->auditValue($value)];
        }

        return $changes;
    }

    /** @return array<string, array{from?: mixed, to?: mixed, changed?: bool}> */
    protected function auditSnapshot(bool $asNew): array
    {
        $changes = [];

        foreach ($this->getAttributes() as $field => $value) {
            if ($value === null || $this->auditSkips($field)) {
                continue;
            }

            $changes[$field] = $this->auditSealed($field)
                ? ['changed' => true]
                : ($asNew ? ['to' => $this->auditValue($value)] : ['from' => $this->auditValue($value)]);
        }

        return $changes;
    }

    private function auditSkips(string $field): bool
    {
        $excluded = method_exists($this, 'auditExcluded') ? $this->auditExcluded() : [];

        return in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', ...$this->getHidden(), ...$excluded], true);
    }

    private function auditSealed(string $field): bool
    {
        return in_array($field, SealedFields::of($this), true);
    }

    private function auditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value), is_int($value), is_float($value), is_string($value), $value === null => $value,
            default => (string) json_encode($value),
        };
    }

    private function defaultAuditLabel(): string
    {
        $name = $this->getAttribute('description') ?? $this->getAttribute('name') ?? null;
        $type = str(class_basename($this))->headline()->toString();

        return is_string($name) && $name !== '' ? "{$type}: {$name}" : "{$type} #{$this->getKey()}";
    }
}
