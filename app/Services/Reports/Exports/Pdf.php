<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use App\Models\Company;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Renders export views to PDF with dompdf (D61): A4, the brand's text and money fonts
 * embedded from resources/fonts, Indian money formatting with a true minus.
 */
final class Pdf
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $fonts = [
            'sans' => resource_path('fonts/plus-jakarta-sans-latin-400-normal.ttf'),
            'sansBold' => resource_path('fonts/plus-jakarta-sans-latin-600-normal.ttf'),
            'sansExt' => resource_path('fonts/plus-jakarta-sans-latin-ext-400-normal.ttf'),
            'mono' => resource_path('fonts/ibm-plex-mono-latin-400-normal.ttf'),
            'monoBold' => resource_path('fonts/ibm-plex-mono-latin-500-normal.ttf'),
            'monoExt' => resource_path('fonts/ibm-plex-mono-latin-ext-400-normal.ttf'),
        ];

        return DomPdf::loadView($view, [
            ...$data,
            'fonts' => array_map(fn (string $path) => str_replace('\\', '/', $path), $fonts),
            'logo' => $data['logo'] ?? null,
            'generated' => CarbonImmutable::now()->format('j M Y, H:i'),
        ])
            ->setPaper('a4', $orientation)
            ->setOption(['chroot' => [base_path()], 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->output();
    }

    /** The company's logo as a data URI for the PDF header (Settings › Company, D63), if any. */
    public static function logo(Company $company): ?string
    {
        // Settings › Company keeps the logo on the private local disk (CompanyController::LOGO_DISK).
        $disk = Storage::disk('local');

        if ($company->logo_path === null || ! $disk->exists($company->logo_path)) {
            return null;
        }

        $mime = $disk->mimeType($company->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($company->logo_path));
    }

    /**
     * Money for a PDF cell: "₹12,34,567" / "−₹2,66,500", HTML-escaped, with the rupee sign
     * in the latin-ext cut that has the glyph.
     */
    public static function money(int $paise, bool $blankZero = false): string
    {
        if ($blankZero && $paise === 0) {
            return '—';
        }

        return str_replace('₹', '<span class="r">₹</span>', e(Money::format($paise)));
    }

    /** "29.7%", "−4.2%", or "—" when there's no base. */
    public static function percent(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }

        $text = number_format(abs($bps) / 100, 1);

        return ($bps < 0 ? '−' : '').$text.'%';
    }
}
