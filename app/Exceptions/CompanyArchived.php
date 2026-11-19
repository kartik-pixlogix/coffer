<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * A change inside an archived company (V4, D139): it is read-only until an Admin restores
 * it. Answered with an error toast and a step back (a 423 for JSON calls).
 */
final class CompanyArchived extends RuntimeException
{
    public static function for(Company $company): self
    {
        return new self("{$company->name} is archived, so it’s read-only. An Admin can restore it in Settings › Company.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $this->getMessage()], 423);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back();
    }
}
