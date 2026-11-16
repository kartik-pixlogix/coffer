<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices and receipts (spec §9, §10): PDF, JPG or PNG up to 10 MB, kept on the private
 * disk under the company's folder with a random name, and only ever served through an
 * authorised route.
 */
final class Attachments
{
    public const string DISK = 'local';

    /** Validation rules shared by income invoices and expense receipts. */
    public const array RULES = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];

    /** @return array{path: string, name: string, mime: string, size: int} */
    public function store(Company $company, string $folder, UploadedFile $file): array
    {
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = $file->storeAs("companies/{$company->id}/{$folder}", Str::random(40).'.'.$extension, self::DISK);

        return [
            'path' => (string) $path,
            'name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Shown inline (images and PDFs preview in the drawer) unless `$download`. */
    public function response(string $path, string $name, ?string $mime, bool $download): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ];

        return $download
            ? $disk->download($path, $name, $headers)
            : $disk->response($path, $name, $headers);
    }
}
