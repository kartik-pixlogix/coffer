<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * TDS sections Coffer deducts under (D160), by their familiar Income-tax Act 1961 numbers.
 * From FY 2026-27 the Income-tax Act 2025 moves them into the tables of section 393; the rates
 * below are [VERIFY] with the CA. Salary (192) is worked out per person on their regime and
 * declarations, so it has no flat rate: a monthly amount is entered on the member.
 */
enum TdsSection: string
{
    case Salary = '192';
    case ContractorIndividual = '194C_individual';
    case ContractorOther = '194C_other';
    case Professional = '194J_professional';
    case Technical = '194J_technical';
    case RentBuilding = '194I_building';
    case RentPlant = '194I_plant';
    case Commission = '194H';
    case PurchaseOfGoods = '194Q';

    /** The section number on the challan and the return. */
    public function code(): string
    {
        return explode('_', $this->value)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::Salary => '192 · Salary',
            self::ContractorIndividual => '194C · Contractor (individual or HUF), 1%',
            self::ContractorOther => '194C · Contractor (firm or company), 2%',
            self::Professional => '194J · Professional fees, 10%',
            self::Technical => '194J · Technical services, 2%',
            self::RentBuilding => '194I · Rent of land or building, 10%',
            self::RentPlant => '194I · Rent of plant or machinery, 2%',
            self::Commission => '194H · Commission or brokerage, 2%',
            self::PurchaseOfGoods => '194Q · Purchase of goods, 0.1%',
        };
    }

    /** Basis points of the taxable value; null where the amount is worked out per person (192). */
    public function rateBps(): ?int
    {
        return match ($this) {
            self::Salary => null,
            self::ContractorIndividual => 100,
            self::ContractorOther => 200,
            self::Professional => 1000,
            self::Technical => 200,
            self::RentBuilding => 1000,
            self::RentPlant => 200,
            self::Commission => 200,
            self::PurchaseOfGoods => 10,
        };
    }

    /** The yearly threshold below which no TDS is due, as a hint (not enforced). [VERIFY] */
    public function thresholdNote(): string
    {
        return match ($this) {
            self::Salary => 'Only when the year’s salary is above the taxable limit',
            self::ContractorIndividual, self::ContractorOther => 'Above ₹30,000 a bill or ₹1,00,000 a year',
            self::Professional, self::Technical => 'Above ₹50,000 a year',
            self::RentBuilding, self::RentPlant => 'Above ₹50,000 a month',
            self::Commission => 'Above ₹20,000 a year',
            self::PurchaseOfGoods => 'Above ₹50 lakh a year from one seller',
        };
    }

    /** @return list<array{value: string, label: string}> sections for vendor bills (not salary) */
    public static function vendorOptions(): array
    {
        return array_values(array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            array_filter(self::cases(), fn (self $s) => $s !== self::Salary),
        ));
    }
}
