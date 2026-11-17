<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\PlanLimit;
use App\Http\Controllers\Controller;
use App\Jobs\CommitVaultImport;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultImport;
use App\Services\Billing\PlanUsage;
use App\Services\Vault\Transfer\ImportParser;
use App\Services\Vault\Transfer\VaultImporter;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Vault › Import (spec §14.4 "through the same upload → map → preview flow as §8.2",
 * D77): CSV, Bitwarden JSON/CSV, 1Password, LastPass, Chrome or a Coffer export, into a
 * vault you can add items to. The upload is sealed straight away and deleted after the
 * commit (queued) or on cancel. The preview shows names and which secrets came in, never
 * a value.
 */
final class VaultImportController extends Controller
{
    public function __construct(private readonly VaultAccess $access, private readonly VaultImporter $importer) {}

    public function index(Request $request, VaultSession $session): Response
    {
        $user = $this->user($request);
        $vaults = $this->access->creatableVaults($user);
        abort_if($vaults->isEmpty(), 403);
        $open = $session->opensFor($user);

        $import = $open ? $this->current($request, $user) : null;
        $step = match (true) {
            $import === null => 'upload',
            $import->status !== 'uploaded' => 'done',
            $import->format->needsMapping() && ! $request->boolean('preview') => 'map',
            default => 'preview',
        };

        $preview = null;
        $error = null;

        if ($import !== null && $step === 'preview') {
            try {
                $preview = $this->importer->preview($import);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('vault/import', [
            'vaultState' => $session->pageState($user),
            'vaults' => $vaults->map(fn (Vault $v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'step' => $step,
            'import' => $import === null ? null : [
                'id' => $import->id,
                'name' => $import->name,
                'format' => $import->format->value,
                'formatLabel' => $import->format->label(),
                'vault' => $import->vault->name,
                'rows' => $import->rows,
                'header' => $import->header,
                'mapping' => $import->mapping,
                'status' => $import->status,
                'summary' => $import->summary,
            ],
            'preview' => $preview,
            'error' => $error,
            'fields' => ImportParser::FIELDS,
            'maxKb' => VaultImporter::MAX_KB,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.VaultImporter::MAX_KB],
            'vault_id' => ['required', 'integer'],
            'passphrase' => ['nullable', 'string', 'max:1024'],
        ], [
            'file.required' => 'Choose a file to import.',
            'file.max' => 'This file is over 5 MB. Split it into smaller files.',
        ]);

        $vault = Vault::query()->find((int) $data['vault_id']);

        if ($vault === null || ! $this->access->canCreateIn($user, $vault)) {
            throw ValidationException::withMessages(['vault_id' => 'Pick a vault you can add items to.']);
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $import = $this->importer->store($user, $vault, $file, $data['passphrase'] ?? null);
        } catch (RuntimeException $e) {
            $field = str_contains($e->getMessage(), 'passphrase') ? 'passphrase' : 'file';

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return to_route('vault.import', ['import' => $import->id]);
    }

    public function mapping(Request $request, VaultImport $import): RedirectResponse
    {
        $this->own($request, $import);
        abort_unless($import->isOpen() && $import->format->needsMapping(), 409);

        $data = $request->validate([
            'mapping' => ['required', 'array', 'size:'.count($import->header ?? [])],
            'mapping.*' => ['nullable', Rule::in(ImportParser::FIELDS)],
        ]);

        $mapping = [];

        foreach (array_keys($import->header ?? []) as $i) {
            $mapping[$i] = $data['mapping'][$i] ?? null;
        }

        $fields = array_filter($mapping);

        if (count($fields) !== count(array_unique($fields))) {
            throw ValidationException::withMessages(['mapping' => 'Each Coffer field can only come from one column.']);
        }

        if (! in_array('name', $fields, true) && ! in_array('url', $fields, true)) {
            throw ValidationException::withMessages(['mapping' => 'Map a column to Name or URL so each item has a name.']);
        }

        $import->update(['mapping' => $mapping]);

        return to_route('vault.import', ['import' => $import->id, 'preview' => 1]);
    }

    public function commit(Request $request, VaultImport $import, PlanUsage $usage, CurrentCompany $current): RedirectResponse
    {
        $this->own($request, $import);
        abort_unless($import->isOpen(), 409);
        // Spec §20 soft limit (D82): every row could become an item, so the whole file must fit.
        $usage->ensureCanAdd($current->require()->workspace, PlanLimit::VaultItems, max(1, $import->rows));

        $import->update(['status' => 'queued']);
        CommitVaultImport::dispatch($import->id);

        return to_route('vault.import', ['import' => $import->id]);
    }

    public function destroy(Request $request, VaultImport $import): RedirectResponse
    {
        $this->own($request, $import);
        $this->importer->discard($import);

        if ($import->isOpen()) {
            $import->update(['status' => 'cancelled', 'finished_at' => now()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import cancelled. The uploaded file was deleted.']);

        return to_route('vault.import');
    }

    private function current(Request $request, User $user): ?VaultImport
    {
        $id = $request->integer('import');

        if ($id > 0) {
            $import = VaultImport::query()->with('vault')->find($id);
            abort_if($import === null || $import->user_id !== $user->id, 404);

            return $import;
        }

        return VaultImport::query()->with('vault')->where('user_id', $user->id)->where('status', 'uploaded')->latest('id')->first();
    }

    private function own(Request $request, VaultImport $import): void
    {
        abort_unless($import->user_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
