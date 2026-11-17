<x-mail::message>
# {{ $lead->topic->label() }}

**{{ $lead->name }}** ({{ $lead->email }}) sent a request from the website.

<x-mail::table>
| | |
|:--|:--|
| Topic | {{ $lead->topic->label() }} |
| Company | {{ $lead->company ?? '—' }} |
| Phone | {{ $lead->phone ?? '—' }} |
| Team size | {{ $lead->team_size ?? '—' }} |
| Sent | {{ $lead->created_at?->timezone('Asia/Kolkata')->format('j M Y, g:i a') }} IST |
</x-mail::table>

@if ($lead->message)
<x-mail::panel>
{{ $lead->message }}
</x-mail::panel>
@endif

Reply to this email to answer {{ $lead->name }} directly.
</x-mail::message>
