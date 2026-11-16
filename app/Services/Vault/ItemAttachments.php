<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\User;
use App\Models\VaultAttachment;
use App\Models\VaultItem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Attachments 🔒 (spec §14.1): the bytes are sealed with the company data key (AAD
 * "vault_attachment:{id}") and written to the private disk under a random name, so
 * the file on disk is ciphertext and its name says nothing.
 */
final readonly class ItemAttachments
{
    public function __construct(private VaultCrypto $crypto) {}

    public function store(VaultItem $item, UploadedFile $file, ?User $by): VaultAttachment
    {
        $bytes = file_get_contents($file->getRealPath()) ?: throw new RuntimeException('The upload could not be read.');

        $attachment = VaultAttachment::query()->create([
            'company_id' => $item->company_id,
            'vault_item_id' => $item->id,
            'name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getClientMimeType(),
            'size' => strlen($bytes),
            'path' => "companies/{$item->company_id}/vault/".Str::random(40).'.bin',
            'key_version' => 0,
            'uploaded_by' => $by?->id,
        ]);

        $this->write($attachment, $bytes);

        return $attachment;
    }

    public function read(VaultAttachment $attachment): string
    {
        $sealed = $this->disk()->get($attachment->path) ?? throw new RuntimeException('The attachment file is missing.');

        return $this->crypto->decrypt($attachment->company_id, $sealed, $attachment->key_version, VaultAttachment::aad($attachment->id), 'attachment');
    }

    public function delete(VaultAttachment $attachment): void
    {
        $this->disk()->delete($attachment->path);
        $attachment->delete();
    }

    /** Re-seal under the active key (data-key rotation): new file first, then the row, then the old file goes. */
    public function reseal(VaultAttachment $attachment): void
    {
        $bytes = $this->read($attachment);
        $old = $attachment->path;
        $attachment->path = "companies/{$attachment->company_id}/vault/".Str::random(40).'.bin';
        $this->write($attachment, $bytes);
        $this->disk()->delete($old);
    }

    private function write(VaultAttachment $attachment, string $bytes): void
    {
        $sealed = $this->crypto->encrypt($attachment->company_id, $bytes, VaultAttachment::aad($attachment->id));
        $this->disk()->put($attachment->path, $sealed->ciphertext);
        $attachment->forceFill(['key_version' => $sealed->keyVersion])->save();
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('vault.attachments_disk'));
    }
}
