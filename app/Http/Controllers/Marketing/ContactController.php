<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Enums\ContactTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ContactRequestForm;
use App\Mail\ContactRequestReceived;
use App\Models\ContactRequest;
use App\Support\Marketing\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact / Book a demo (spec §21, D84): stores the lead and emails the team (queued).
 * A filled honeypot gets the same thank-you without storing or sending anything; the
 * POST is rate-limited per IP (the `contact` limiter).
 */
final class ContactController extends Controller
{
    public function show(Request $request): Response
    {
        $topic = ContactTopic::tryFrom((string) $request->query('topic')) ?? ContactTopic::Demo;

        return Inertia::render('marketing/contact', [
            'topic' => $topic->value,
            'topics' => array_map(fn (ContactTopic $t) => ['value' => $t->value, 'label' => $t->label()], ContactTopic::cases()),
            'teamSizes' => ContactRequestForm::TEAM_SIZES,
            'sent' => $request->session()->get('contact.sent'),
            'seo' => Seo::for($request),
        ]);
    }

    public function store(ContactRequestForm $request): RedirectResponse
    {
        /** @var array{topic: string, name: string, email: string, company?: string|null, phone?: string|null, team_size?: string|null, message?: string|null} $data */
        $data = $request->validated();

        if (! $request->isSpam()) {
            $lead = ContactRequest::query()->create([
                'topic' => $data['topic'],
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'company' => $data['company'] ?? null,
                'phone' => $data['phone'] ?? null,
                'team_size' => $data['team_size'] ?? null,
                'message' => $data['message'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);

            Mail::to((string) config('marketing.contact_email'))->queue(new ContactRequestReceived($lead));
        }

        return redirect()->route('marketing.contact')->with('contact.sent', [
            'topic' => $data['topic'],
            'name' => Str::before($data['name'], ' '),
        ]);
    }
}
