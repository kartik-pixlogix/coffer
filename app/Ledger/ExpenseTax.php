<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\TdsSection;

/**
 * The GST and TDS on an expense, for posting (D161). Forward charge: the supplier charged the
 * GST, so the bill is taxable + GST. Reverse charge (services imported from abroad): the bill
 * is the taxable value and the company pays the GST itself. With input credit the GST is an
 * asset; without it the GST is part of the cost. TDS is held back from the payment.
 */
final readonly class ExpenseTax
{
    public function __construct(
        public int $taxable,
        public int $cgst = 0,
        public int $sgst = 0,
        public int $igst = 0,
        public bool $reverseCharge = false,
        public bool $itc = false,
        public ?TdsSection $tdsSection = null,
        public int $tds = 0,
    ) {}

    public function gst(): int
    {
        return $this->cgst + $this->sgst + $this->igst;
    }

    /** What the bill comes to: GST included unless the company pays it under reverse charge. */
    public function billTotal(): int
    {
        return $this->taxable + ($this->reverseCharge ? 0 : $this->gst());
    }

    /** What the payers pay: the bill less the TDS held back. */
    public function payable(): int
    {
        return $this->billTotal() - $this->tds;
    }

    /** The cost in the books: the taxable value, plus GST that can't be claimed back. */
    public function cost(): int
    {
        return $this->taxable + ($this->itc ? 0 : $this->gst());
    }

    /** @return array<string, int> head → amount, non-zero only */
    public function heads(): array
    {
        return array_filter(['cgst' => $this->cgst, 'sgst' => $this->sgst, 'igst' => $this->igst], fn (int $v) => $v !== 0);
    }
}
