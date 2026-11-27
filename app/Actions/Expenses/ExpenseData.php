<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Enums\PayerMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * A validated expense form (spec §10). Either an existing category or a new one typed
 * in "+ Add category"; payer lines already add up to the amount (less any TDS held back).
 */
final readonly class ExpenseData
{
    /** @param list<PayerInput> $payers */
    public function __construct(
        public string $description,
        public int $amountPaise,
        public CarbonImmutable $spentOn,
        public ?int $categoryId,
        public ?string $newCategory,
        public ?int $projectId,
        public bool $billable,
        public ?string $notes,
        public PayerMode $mode,
        public array $payers,
        public ?UploadedFile $receipt = null,
        public bool $removeReceipt = false,
        /** @var array<string, mixed>|null the bill's GST and TDS for `document_taxes` (D162) */
        public ?array $tax = null,
    ) {}
}
