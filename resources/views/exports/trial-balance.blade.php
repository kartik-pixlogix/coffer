@php use App\Services\Reports\Exports\Pdf; @endphp
@extends('exports.layout')

@section('content')
<div class="status {{ $trial['drift'] === 0 ? 'ok' : 'bad' }}">
@if ($trial['drift'] === 0)
Balanced. Debits and credits both total <span class="m">{!! Pdf::money($trial['debit']) !!}</span>.
@else
Out of balance by <span class="m">{!! Pdf::money(abs($trial['drift'])) !!}</span>: debits <span class="m">{!! Pdf::money($trial['debit']) !!}</span>, credits <span class="m">{!! Pdf::money($trial['credit']) !!}</span>.
@endif
</div>
<table>
<tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr>
@foreach ($trial['accounts'] as $a)
<tr>
<td class="m">{{ $a['code'] }}</td>
<td>{{ $a['name'] }}</td>
<td>{{ ucfirst($a['type']) }}</td>
<td class="num m">{!! Pdf::money($a['debit'], true) !!}</td>
<td class="num m">{!! Pdf::money($a['credit'], true) !!}</td>
</tr>
@endforeach
<tr class="total"><td></td><td>Total</td><td></td><td class="num m">{!! Pdf::money($trial['debit']) !!}</td><td class="num m">{!! Pdf::money($trial['credit']) !!}</td></tr>
</table>
<p class="muted">Balance-sheet accounts run from the beginning; income and expenses from the start of the fiscal year, before closing.</p>
@endsection
