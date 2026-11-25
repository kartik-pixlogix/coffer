@php
use App\Services\Reports\Exports\Pdf;
$fmt = fn (?string $d) => $d === null ? null : \Carbon\CarbonImmutable::parse($d)->format('j M Y');
$current = $fmt($pnl['from']).' – '.$fmt($pnl['to']);
$previous = $pnl['previousFrom'] === null ? null : $fmt($pnl['previousFrom']).' – '.$fmt($pnl['previousTo']);
@endphp
@extends('exports.layout')

@section('content')
<table>
<tr><th>Particulars</th><th class="num" style="width: 40px;">Note</th><th class="num">{{ $current }}</th>@if ($previous)<th class="num">{{ $previous }}</th>@endif</tr>
@foreach ($pnl['lines'] as $line)
<tr @if ($line['strong']) class="total" @endif><td>{{ $line['label'] }}</td><td class="num muted">{{ $line['note'] }}</td><td class="num m">{!! $line['amount'] === null ? '' : Pdf::money($line['amount'], true) !!}</td>@if ($previous)<td class="num m">{!! $line['amount'] === null || $line['previous'] === null ? '' : Pdf::money($line['previous'], true) !!}</td>@endif</tr>
@endforeach
</table>
<p class="muted">A firm’s statement in the Schedule III (Division I) format: income-tax on the partners’ shares is outside these books, so the tax lines are nil.</p>
@include('exports.schedule-notes', ['notes' => $pnl['notes'], 'current' => $current, 'previous' => $previous])
@endsection
