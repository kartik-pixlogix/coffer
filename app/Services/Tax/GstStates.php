<?php

declare(strict_types=1);

namespace App\Services\Tax;

/**
 * The GST state and union territory codes (the first two digits of a GSTIN, D159). 25 (Daman
 * and Diu) merged into 26 in 2020 and 28 (Andhra Pradesh, old) is no longer issued; 97 is
 * "Other territory". [VERIFY] against the GSTN list when the CA reviews.
 */
final class GstStates
{
    /** @var array<string, string> */
    public const array ALL = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other territory',
    ];

    /** A GSTIN: state code, PAN, entity number, Z, check character (D159: format only, no checksum). */
    public const string GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const string PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const string TAN_PATTERN = '/^[A-Z]{4}[0-9]{5}[A-Z]$/';

    public static function exists(?string $code): bool
    {
        return $code !== null && isset(self::ALL[$code]);
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::ALL[$code] ?? null);
    }

    /** The state a GSTIN is registered in, if it's a valid code. */
    public static function ofGstin(?string $gstin): ?string
    {
        $code = $gstin === null ? null : substr($gstin, 0, 2);

        return self::exists($code) ? $code : null;
    }

    /** The PAN inside a GSTIN (characters 3–12). */
    public static function panOf(string $gstin): string
    {
        return substr($gstin, 2, 10);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALL as $code => $name) {
            $options[] = ['value' => (string) $code, 'label' => "{$code} · {$name}"];
        }

        return $options;
    }
}
