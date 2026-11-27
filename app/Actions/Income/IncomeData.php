<?php

declare(strict_types=1);

namespace App\Actions\Income;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/** A validated income form (spec §9): into a money account or to a partner, never both. */
final readonly class IncomeData
{
    public function __construct(
        public string $source,
        public int $amountPaise,
        public CarbonImmutable $receivedOn,
        public int $categoryId,
        public ?int $broughtInBy,
        public ?int $moneyAccountId,
        public ?int $receivedBy,
        public ?int $projectId,
        public ?string $notes,
        public ?UploadedFile $attachment = null,
        public bool $removeAttachment = false,
        /** @var array<string, mixed>|null the invoice's GST and TDS for `document_taxes` (D162) */
        public ?array $tax = null,
    ) {}

    /** @return array<string, mixed> the posted fields, as stored */
    public function attributes(): array
    {
        return [
            'source' => $this->source,
            'amount_paise' => $this->amountPaise,
            'received_on' => $this->receivedOn->toDateString(),
            'category_id' => $this->categoryId,
            'brought_in_by' => $this->broughtInBy,
            'money_account_id' => $this->moneyAccountId,
            'received_by' => $this->receivedBy,
            'project_id' => $this->projectId,
            'notes' => $this->notes,
        ];
    }
}
