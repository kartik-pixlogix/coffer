<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Services\Security\FieldCrypto;
use App\Services\Security\SealedFields;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Seals the model's sensitive columns (SealedFields, D149) with the company's data key on the
 * way into the database and opens them on the way out, so the app works with plaintext and
 * the database (and every dump of it) only holds ciphertext. Sealing happens at save time,
 * not on assignment, so it doesn't matter whether `company_id` is filled before the field.
 *
 * Writes that skip model events (`insert()`, query `update()`) must seal for themselves:
 * SealedFields::sealRows() for bulk inserts.
 *
 * @mixin Model
 */
trait EncryptsFields
{
    /** @var array<string, string> plaintext of the fields being saved, put back once saved */
    private array $plainSealedFields = [];

    public static function bootEncryptsFields(): void
    {
        static::retrieved(fn (self $model) => $model->openSealedFields());
        static::creating(fn (self $model) => $model->sealDirtyFields());
        static::updating(fn (self $model) => $model->sealDirtyFields());
        static::saved(fn (self $model) => $model->restoreSealedFields());
    }

    public function openSealedFields(): void
    {
        foreach (SealedFields::of($this) as $field) {
            $raw = $this->attributes[$field] ?? null;

            if (FieldCrypto::isSealed($raw)) {
                $this->attributes[$field] = app(FieldCrypto::class)->open($this->sealingCompanyId(), $raw, $this->sealedFieldName($field));
                $this->syncOriginalAttribute($field);
            }
        }
    }

    public function sealDirtyFields(): void
    {
        foreach (SealedFields::of($this) as $field) {
            $value = $this->attributes[$field] ?? null;

            if (! is_string($value) || FieldCrypto::isSealed($value) || ($this->exists && ! $this->isDirty($field))) {
                continue;
            }

            $this->plainSealedFields[$field] = $value;
            $this->attributes[$field] = app(FieldCrypto::class)->seal($this->sealingCompanyId(), $value, $this->sealedFieldName($field));
        }
    }

    public function restoreSealedFields(): void
    {
        foreach ($this->plainSealedFields as $field => $plain) {
            $this->attributes[$field] = $plain;
            $this->syncOriginalAttribute($field);
        }

        $this->plainSealedFields = [];
    }

    private function sealedFieldName(string $field): string
    {
        return $this->getTable().'.'.$field;
    }

    private function sealingCompanyId(): int
    {
        $id = $this instanceof Company
            ? $this->getKey()
            : ($this->attributes['company_id'] ?? app(CurrentCompany::class)->id());

        if (! is_numeric($id)) {
            throw new LogicException($this instanceof Company
                ? 'A company’s sensitive fields are set once the company exists (it names their key).'
                : static::class.' needs a company before its sensitive fields can be saved.');
        }

        return (int) $id;
    }
}
