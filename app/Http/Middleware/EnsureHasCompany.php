<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App pages need a company. A user with none yet (a new sign-up) goes to the wizard.
 */
final class EnsureHasCompany
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->current->get() === null) {
            return redirect()->route('companies.create');
        }

        return $next($request);
    }
}
