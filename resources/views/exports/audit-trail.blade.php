@extends('exports.layout')

@section('content')
<p class="muted">Every create, edit, reversal, approval and settings change on money records. The trail is append-only and hash-chained; fields that are encrypted at rest show as “changed (encrypted)”.</p>
<table>
<tr><th>#</th><th>Time</th><th>Person</th><th>Action</th><th>Record</th><th>Voucher</th><th>Changes</th><th>IP</th></tr>
@forelse ($events as $e)
<tr>
<td class="m">{{ $e['seq'] }}</td>
<td style="white-space: nowrap;">{{ $e['time'] }}</td>
<td>{{ $e['person'] }}@if ($e['impersonator'])<br><span class="muted">Support: {{ $e['impersonator'] }}</span>@endif</td>
<td>{{ $e['actionLabel'] }}</td>
<td>{{ $e['label'] ?? '—' }}</td>
<td class="m">{{ $e['voucher'] ?? '' }}</td>
<td>@foreach ($e['changes'] as $c){{ $c['field'] }}: {{ \Illuminate\Support\Str::limit($c['text'], 160) }}@if (! $loop->last)<br>@endif @endforeach</td>
<td class="m">{{ $e['ip'] ?? '' }}</td>
</tr>
@empty
<tr><td colspan="8" class="muted">No events in these dates.</td></tr>
@endforelse
</table>
@endsection
