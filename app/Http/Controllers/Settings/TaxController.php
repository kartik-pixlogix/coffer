<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\GstFiledBy;
use App\Enums\GstRegistration;
use App\Http\Controllers\Controller;
use App\Services\Tax\GstStates;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Tax (D159): the company's GST registration (type, state, GSTIN), its TAN for
 * TDS, and who files the returns. Registered companies need a GSTIN whose first two digits
 * are their state. No design board covers it (v2): it uses the Settings card layout.
 */
final class TaxController extends Controller
{
    public function index(CurrentCompany $current): Response
    {
        $company = $current->require();

        return Inertia::render('settings/tax', [
            'tax' => [
                'gstRegistration' => $company->gst_registration->value,
                'stateCode' => $company->state_code,
                'gstin' => $company->gstin,
                'tan' => $company->tan,
                'gstFiledBy' => $company->gst_filed_by->value,
            ],
            'options' => [
                'registrations' => array_map(fn (GstRegistration $r) => ['value' => $r->value, 'label' => $r->label()], GstRegistration::cases()),
                'states' => GstStates::options(),
                'filedBy' => array_map(fn (GstFiledBy $f) => ['value' => $f->value, 'label' => $f->label()], GstFiledBy::cases()),
            ],
        ]);
    }

    public function update(Request $request, CurrentCompany $current): RedirectResponse
    {
        $company = $current->require();
        $request->merge([
            'gstin' => self::normalise($request->input('gstin')),
            'tan' => self::normalise($request->input('tan')),
        ]);

        $data = $request->validate([
            'gst_registration' => ['required', Rule::enum(GstRegistration::class)],
            'state_code' => ['nullable', Rule::in(array_keys(GstStates::ALL))],
            'gstin' => ['nullable', 'regex:'.GstStates::GSTIN_PATTERN],
            'tan' => ['nullable', 'regex:'.GstStates::TAN_PATTERN],
            'gst_filed_by' => ['required', Rule::enum(GstFiledBy::class)],
        ], [
            'gstin.regex' => 'A GSTIN is 15 characters, like 27ABCDE1234F1Z5.',
            'tan.regex' => 'A TAN is 10 characters, like PNEV01234B.',
        ]);

        validator($data)->after(function (Validator $v) use ($data): void {
            $registered = $data['gst_registration'] !== GstRegistration::Unregistered->value;

            if ($registered && ($data['gstin'] ?? null) === null) {
                $v->errors()->add('gstin', 'A registered company needs its GSTIN.');
            }

            if (($data['state_code'] ?? null) === null && ($registered || ($data['gstin'] ?? null) !== null)) {
                $v->errors()->add('state_code', 'Choose the state the company is registered in.');
            }

            if (($data['gstin'] ?? null) !== null && ($data['state_code'] ?? null) !== null && ! str_starts_with((string) $data['gstin'], (string) $data['state_code'])) {
                $v->errors()->add('gstin', 'This GSTIN is for state '.substr((string) $data['gstin'], 0, 2).', not '.$data['state_code'].'. The first two digits are the state code.');
            }
        })->validate();

        $company->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tax details saved']);

        return back();
    }

    private static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper((string) preg_replace('/\s+/', '', $value));

        return $value === '' ? null : $value;
    }
}
