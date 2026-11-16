<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The create-company wizard (CreateCompany*.dc.html, spec §3.1). */
final class CreateCompanyController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('companies/create');
    }
}
