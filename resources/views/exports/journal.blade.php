@php use App\Services\Reports\Exports\Pdf; @endphp
@extends('exports.layout')

@section('content')
<table>
<tr><th>Voucher</th><th>Date</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr>
@forelse ($entries as $e)
<tr>
<td class="m" style="white-space: nowrap;">{{ $e['voucher'] ?? '—' }}</td>
<td style="white-space: nowrap;">{{ \Carbon\CarbonImmutable::parse($e['date'])->format('j M Y') }}</td>
<td><b>{{ $e['typeLabel'] }}</b>@if ($e['memo']) · {{ $e['memo'] }}@endif @if ($e['reverses'])<br><span class="muted">Reverses {{ $e['reverses'] }}</span>@endif @if ($e['reversedBy'])<br><span class="muted">Reversed by {{ $e['reversedBy'] }}</span>@endif</td>
<td></td><td></td>
</tr>
@foreach ($e['lines'] as $l)
<tr>
<td></td><td></td>
<td style="padding-left: {{ $l['creditPaise'] > 0 ? 24 : 10 }}px;"><span class="m">{{ $l['code'] }}</span> {{ $l['name'] }}</td>
<td class="num m">{!! Pdf::money($l['debitPaise'], true) !!}</td>
<td class="num m">{!! Pdf::money($l['creditPaise'], true) !!}</td>
</tr>
@endforeach
@empty
<tr><td colspan="5" class="muted">No vouchers in these dates.</td></tr>
@endforelse
</table>
@endsection
