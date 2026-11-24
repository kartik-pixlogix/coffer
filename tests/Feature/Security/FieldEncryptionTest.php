<?php

declare(strict_types=1);

use App\Exceptions\Vault\VaultDecryptionFailed;
use App\Jobs\ReencryptVaultData;
use App\Models\Company;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Finance\Attachments;
use App\Services\Security\FieldCrypto;
use App\Services\Security\FieldRotation;
use App\Services\Security\SealedFields;
use App\Services\Vault\VaultKeys;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Field-level encryption of sensitive business data (Phase 10, D149): the database holds
| `cf1:` ciphertext under the company's data key; the app reads and writes plaintext.
*/

test('a value seals under the company key and opens again; the AAD pins company and field', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $crypto = app(FieldCrypto::class);

    $sealed = $crypto->seal($a->id, '27ABCDE1234F1Z5', 'companies.gstin');
    $crypto->seal($b->id, 'x', 'companies.gstin'); // b has a data key of its own

    expect($sealed)->toStartWith('cf1:1:')->not->toContain('ABCDE')
        ->and($crypto->open($a->id, $sealed, 'companies.gstin'))->toBe('27ABCDE1234F1Z5')
        ->and($crypto->seal($a->id, '27ABCDE1234F1Z5', 'companies.gstin'))->not->toBe($sealed);

    expect(fn () => $crypto->open($b->id, $sealed, 'companies.gstin'))->toThrow(VaultDecryptionFailed::class);
    expect(fn () => $crypto->open($a->id, $sealed, 'companies.address'))->toThrow(VaultDecryptionFailed::class);

    // Legacy plaintext (before the backfill) reads as it is.
    expect($crypto->open($a->id, 'plain', 'companies.gstin'))->toBe('plain');
});

test('blind indexes match equal values in one company and field only', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $crypto = app(FieldCrypto::class);

    expect($crypto->blindIndex($a->id, ' Rohan@Example.com ', 'members.email'))->toBe($crypto->blindIndex($a->id, 'rohan@example.com', 'members.email'))
        ->and($crypto->blindIndex($b->id, 'rohan@example.com', 'members.email'))->not->toBe($crypto->blindIndex($a->id, 'rohan@example.com', 'members.email'))
        ->and($crypto->blindIndex($a->id, 'rohan@example.com', 'partners.email'))->not->toBe($crypto->blindIndex($a->id, 'rohan@example.com', 'members.email'));
});

test('models store ciphertext and read plaintext', function () {
    $books = singleBooks();
    $member = Member::factory()->create(['partner_id' => $books->aarav->id, 'email' => 'rohan@vertexlabs.in']);

    $raw = DB::table('members')->where('id', $member->id)->value('email');

    expect($raw)->toStartWith('cf1:')->not->toContain('rohan')
        ->and($member->email)->toBe('rohan@vertexlabs.in')
        ->and(Member::query()->withoutGlobalScopes()->findOrFail($member->id)->email)->toBe('rohan@vertexlabs.in')
        ->and($member->isDirty('email'))->toBeFalse();

    // Saving something else leaves the sealed value alone; changing it re-seals.
    $member->update(['designation' => 'Lead']);
    expect(DB::table('members')->where('id', $member->id)->value('email'))->toBe($raw);

    $member->update(['email' => 'rohan.desai@vertexlabs.in']);
    expect(DB::table('members')->where('id', $member->id)->value('email'))->not->toBe($raw)->toStartWith('cf1:')
        ->and($member->fresh()?->email)->toBe('rohan.desai@vertexlabs.in');
});

test('a company’s GSTIN and address are sealed; they can only be set once the company exists', function () {
    $company = Company::factory()->create();
    $company->update(['gstin' => '27ABCDE1234F1Z5', 'address' => 'Office 402, Baner Road, Pune']);

    $row = DB::table('companies')->where('id', $company->id)->first(['gstin', 'address']);

    expect($row->gstin)->toStartWith('cf1:')
        ->and($row->address)->toStartWith('cf1:')->not->toContain('Baner')
        ->and($company->fresh()?->gstin)->toBe('27ABCDE1234F1Z5');

    expect(fn () => Company::factory()->create(['gstin' => '27ABCDE1234F1Z5']))->toThrow(LogicException::class);
});

test('bulk inserts seal through SealedFields::sealRows', function () {
    $books = singleBooks();
    $member = Member::factory()->create(['partner_id' => $books->aarav->id]);
    $project = Project::query()->create(['company_id' => $books->company->id, 'name' => 'Asha Retail store']);

    TimeEntry::query()->insert(SealedFields::sealRows(TimeEntry::class, [[
        'company_id' => $books->company->id, 'member_id' => $member->id, 'partner_id' => $books->aarav->id, 'project_id' => $project->id,
        'date' => fyDay(10)->toDateString(), 'hours_centi' => 100, 'billable' => false, 'notes' => 'Stand-up and review',
        'created_at' => now(), 'updated_at' => now(),
    ]]));

    expect(DB::table('time_entries')->value('notes'))->toStartWith('cf1:')
        ->and(TimeEntry::query()->withoutGlobalScopes()->sole()->notes)->toBe('Stand-up and review');
});

test('a data-key rotation re-seals business fields before the old key retires', function () {
    $books = singleBooks();
    $member = Member::factory()->create(['partner_id' => $books->aarav->id, 'email' => 'rohan@vertexlabs.in']);
    $books->company->update(['gstin' => '27ABCDE1234F1Z5']);

    $version = app(VaultKeys::class)->addVersion($books->company->id);
    expect(app(FieldRotation::class)->remaining($books->company->id, $version))->toBe(2);

    app()->call([new ReencryptVaultData($books->company->id, $version, chain: false), 'handle']);
    app(VaultKeys::class)->flush();

    expect(app(FieldRotation::class)->remaining($books->company->id, $version))->toBe(0)
        ->and(DB::table('members')->where('id', $member->id)->value('email'))->toStartWith("cf1:{$version}:")
        ->and(Member::query()->withoutGlobalScopes()->findOrFail($member->id)->email)->toBe('rohan@vertexlabs.in')
        ->and(Company::query()->findOrFail($books->company->id)->gstin)->toBe('27ABCDE1234F1Z5');
});

test('a database dump holds no plaintext GSTIN, address, emails, bank labels or notes', function () {
    $this->seed(DatabaseSeeder::class);

    $plain = [];

    foreach (SealedFields::tables() as $table => $spec) {
        foreach ($spec['columns'] as $column) {
            $count = DB::table($table)->whereNotNull($column)->where($column, '<>', '')->where($column, 'not like', 'cf1:%')->count();

            if ($count > 0) {
                $plain[] = "{$table}.{$column}: {$count}";
            }
        }
    }

    expect($plain)->toBe([])
        ->and(DB::table('companies')->whereNotNull('gstin')->count())->toBeGreaterThan(0)
        ->and(DB::table('time_entries')->whereNotNull('notes')->count())->toBeGreaterThan(0);
});

test('partners keep their email sealed too', function () {
    $books = singleBooks();
    $partner = Partner::factory()->for($books->company)->create(['email' => 'kabir@bluefin.in']);

    expect(DB::table('partners')->where('id', $partner->id)->value('email'))->toStartWith('cf1:')
        ->and($partner->fresh()?->email)->toBe('kabir@bluefin.in');
});

test('receipts and invoices are sealed on disk and re-sealed by a key rotation', function () {
    $books = singleBooks();
    $files = app(Attachments::class);
    Storage::fake('local');

    $stored = $files->store($books->company, 'expenses', UploadedFile::fake()->createWithContent('IMG_2041.jpg', 'JPEG-BYTES-2041'));

    expect($stored['path'])->toMatch('#^companies/'.$books->company->id.'/expenses/[A-Za-z0-9]{40}\.v1\.cfe$#')
        ->and(Storage::disk('local')->get($stored['path']))->not->toContain('JPEG-BYTES')
        ->and($files->read($books->company->id, $stored['path']))->toBe('JPEG-BYTES-2041');

    // Another company's key (or a renamed file) can't open it.
    $other = Company::factory()->create();
    app(FieldCrypto::class)->seal($other->id, 'x', 'companies.gstin');
    expect(fn () => $files->read($other->id, $stored['path']))->toThrow(VaultDecryptionFailed::class);

    // A file stored before D149 is still served, and the rotation seals it.
    Storage::disk('local')->put("companies/{$books->company->id}/income/legacy.pdf", 'LEGACY-PDF');
    DB::table('incomes')->insert([
        'company_id' => $books->company->id, 'source' => 'Asha Retail', 'amount_paise' => 100, 'received_on' => fyDay(5)->toDateString(),
        'category_id' => category($books->company, 'income', 'Consulting')->id, 'attachment_path' => "companies/{$books->company->id}/income/legacy.pdf", 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($files->read($books->company->id, "companies/{$books->company->id}/income/legacy.pdf"))->toBe('LEGACY-PDF');

    $version = app(VaultKeys::class)->addVersion($books->company->id);
    expect(app(FieldRotation::class)->remaining($books->company->id, $version))->toBe(1);
    app(FieldRotation::class)->reseal($books->company->id, $version, 50);

    $path = (string) DB::table('incomes')->where('company_id', $books->company->id)->value('attachment_path');
    expect($path)->toEndWith(".v{$version}.cfe")
        ->and($files->read($books->company->id, $path))->toBe('LEGACY-PDF')
        ->and(Storage::disk('local')->exists("companies/{$books->company->id}/income/legacy.pdf"))->toBeFalse()
        ->and(app(FieldRotation::class)->remaining($books->company->id, $version))->toBe(0);
});
