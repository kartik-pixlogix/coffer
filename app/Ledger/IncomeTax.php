<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\TdsSection;

/**
 * The GST charged and the TDS a client deducted on income, for posting (D161): the client
 * paid taxable + GST − TDS; the TDS is claimed back against the company's tax.
 */
final readonly class IncomeTax
{
    public function __construct(
        public int $taxable,
        public int $cgst = 0,
        public int $sgst = 0,
        public int $igst = 0,
        public ?TdsSection $tdsSection = null,
        public int $tds = 0,
    ) {}

    public function gst(): int
    {
        return $this->cgst + $this->sgst + $this->igst;
    }

    /** What reached the company: the invoice less the TDS the client held back. */
    public function received(): int
    {
        return $this->taxable + $this->gst() - $this->tds;
    }

    /** @return array<string, int> head → amount, non-zero only */
    public function heads(): array
    {
        return array_filter(['cgst' => $this->cgst, 'sgst' => $this->sgst, 'igst' => $this->igst], fn (int $v) => $v !== 0);
    }
}
