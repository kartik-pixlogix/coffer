<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PlanLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Creating past a plan limit, or changing anything in a read-only workspace (spec §20,
 * D81, D82). Answered like a validation error under the `plan_limit` key — which the app
 * shell listens for and opens the Upgrade modal — or, for JSON calls, a 402 with the same
 * key. Nothing is ever deleted or hidden.
 */
final class PlanLimitReached extends RuntimeException
{
    /** The validation-error key the front end listens for (resources/js/lib/upgrade.ts). */
    public const string KEY = 'plan_limit';

    public function __construct(
        public readonly ?PlanLimit $limit,
        public readonly int $used,
        public readonly ?int $max,
        public readonly string $plan,
        ?string $message = null,
    ) {
        parent::__construct($message ?? ($limit === null ? '' : sprintf(
            'You’ve used all %d %s on %s. Upgrade to add more — nothing you already have is affected.',
            (int) $max,
            $limit->noun((int) $max),
            $plan,
        )));
    }

    public static function readOnly(string $plan, string $why): self
    {
        return new self(null, 0, null, $plan, "{$why} Everything stays visible; choose a plan to make changes again.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => [self::KEY => [$this->getMessage()]],
                'limit' => $this->limit?->value,
                'used' => $this->used,
                'max' => $this->max,
            ], 402);
        }

        return back()->withErrors([self::KEY => $this->getMessage()]);
    }
}
