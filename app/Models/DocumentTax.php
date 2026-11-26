<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GstSupply;
use App\Enums\TdsSection;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The GST and TDS on one expense (inward) or income (outward), D162: the figures the GSTR-1,
 * GSTR-3B and TDS summaries are built from, kept beside the journal entry they posted.
 * The party's GSTIN and the deductee's PAN are sealed (D149); the GSTIN also has a blind
 * index so the returns can group by party without opening every value.
 *
 * @property int $id
 * @property int $company_id
 * @property string $taxable_type
 * @property int $taxable_id
 * @property string $direction inward (we bought) or outward (we sold)
 * @property GstSupply|null $supply
 * @property string|null $party_gstin
 * @property string|null $party_gstin_index
 * @property string|null $party_state
 * @property string|null $invoice_no
 * @property CarbonImmutable|null $invoice_date
 * @property string|null $hsn_sac
 * @property int $rate_bps
 * @property int $taxable_paise
 * @property int $cgst_paise
 * @property int $sgst_paise
 * @property int $igst_paise
 * @property bool $reverse_charge
 * @property bool $itc input tax credit claimed
 * @property TdsSection|null $tds_section
 * @property int $tds_paise
 * @property string|null $deductee_pan
 */
#[Fillable([
    'company_id', 'direction', 'supply', 'party_gstin', 'party_gstin_index', 'party_state', 'invoice_no', 'invoice_date',
    'hsn_sac', 'rate_bps', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'reverse_charge', 'itc',
    'tds_section', 'tds_paise', 'deductee_pan',
])]
class DocumentTax extends Model
{
    use Audited;
    use BelongsToCompany;
    use EncryptsFields;

    public const string INWARD = 'inward';

    public const string OUTWARD = 'outward';

    protected function casts(): array
    {
        return [
            'supply' => GstSupply::class,
            'invoice_date' => 'immutable_date',
            'rate_bps' => 'integer',
            'taxable_paise' => 'integer',
            'cgst_paise' => 'integer',
            'sgst_paise' => 'integer',
            'igst_paise' => 'integer',
            'reverse_charge' => 'boolean',
            'itc' => 'boolean',
            'tds_section' => TdsSection::class,
            'tds_paise' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function taxable(): MorphTo
    {
        return $this->morphTo();
    }

    public function gstPaise(): int
    {
        return $this->cgst_paise + $this->sgst_paise + $this->igst_paise;
    }

    public function auditLabel(): string
    {
        return 'Tax on '.class_basename($this->taxable_type).' #'.$this->taxable_id;
    }
}
