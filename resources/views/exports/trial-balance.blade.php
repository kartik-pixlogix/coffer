@php
use App\Services\Reports\Exports\Pdf;
$side = fn (int $paise) => $paise === 0 ? '—' : Pdf::money(abs($paise)).' '.($paise > 0 ? 'Dr' : 'Cr');
$balanced = $trial['openingDrift'] === 0 && $trial['movementDrift'] === 0 && $trial['closingDrift'] === 0;
@endphp
@extends('exports.layout')

@section('content')
<div class="status {{ $balanced ? 'ok' : 'bad' }}">
@if ($balanced)
Balanced. Closing debits and credits both total <span class="m">{!! Pdf::money($trial['closingDebit']) !!}</span>; the period’s debits and credits both total <span class="m">{!! Pdf::money($trial['debit']) !!}</span>.
@else
Out of balance: closing debits <span class="m">{!! Pdf::money($trial['closingDebit']) !!}</span>, credits <span class="m">{!! Pdf::money($trial['closingCredit']) !!}</span>; period debits <span class="m">{!! Pdf::money($trial['debit']) !!}</span>, credits <span class="m">{!! Pdf::money($trial['credit']) !!}</span>.
@endif
</div>
<table>
<tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Opening</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Closing</th></tr>
@foreach ($trial['accounts'] as $a)
<tr>
<td class="m">{{ $a['code'] }}</td>
<td>{{ $a['name'] }}</td>
<td>{{ ucfirst($a['type']) }}</td>
<td class="num m">{!! $side($a['opening']) !!}</td>
<td class="num m">{!! Pdf::money($a['debit'], true) !!}</td>
<td class="num m">{!! Pdf::money($a['credit'], true) !!}</td>
<td class="num m">{!! $side($a['closing']) !!}</td>
</tr>
@endforeach
<tr class="total"><td></td><td>Total debits</td><td></td><td class="num m">{!! Pdf::money($trial['openingDebit']) !!}</td><td class="num m">{!! Pdf::money($trial['debit']) !!}</td><td></td><td class="num m">{!! Pdf::money($trial['closingDebit']) !!}</td></tr>
<tr class="total"><td></td><td>Total credits</td><td></td><td class="num m">{!! Pdf::money($trial['openingCredit']) !!}</td><td></td><td class="num m">{!! Pdf::money($trial['credit']) !!}</td><td class="num m">{!! Pdf::money($trial['closingCredit']) !!}</td></tr>
</table>
<p class="muted">Opening + debits − credits = closing on every line. Balance-sheet accounts run from the beginning; income and expenses from the start of the fiscal year, before closing.</p>
@endsection
