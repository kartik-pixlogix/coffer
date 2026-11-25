@php
use App\Services\Reports\Exports\Pdf;
$current = \Carbon\CarbonImmutable::parse($sheet['asOf'])->format('j M Y');
$previous = $sheet['previousAsOf'] === null ? null : \Carbon\CarbonImmutable::parse($sheet['previousAsOf'])->format('j M Y');
@endphp
@extends('exports.layout')

@section('content')
<table>
<tr><th>Particulars</th><th class="num" style="width: 40px;">Note</th><th class="num">As at {{ $current }}</th>@if ($previous)<th class="num">As at {{ $previous }}</th>@endif</tr>
@foreach ($sheet['sections'] as $s => $section)
<tr><td colspan="{{ $previous ? 4 : 3 }}"><b>{{ $s === 0 ? 'I.' : 'II.' }} {{ $section['title'] }}</b></td></tr>
@foreach ($section['groups'] as $g => $group)
@if ($group['title'])<tr><td colspan="{{ $previous ? 4 : 3 }}" style="padding-left: 14px;">({{ $g + 1 }}) {{ $group['title'] }}</td></tr>@endif
@foreach ($group['lines'] as $i => $line)
<tr><td style="padding-left: 28px;">({{ chr(97 + $i) }}) {{ $line['label'] }}</td><td class="num muted">{{ $line['note'] }}</td><td class="num m">{!! Pdf::money($line['amount'], true) !!}</td>@if ($previous)<td class="num m">{!! $line['previous'] === null ? '' : Pdf::money($line['previous'], true) !!}</td>@endif</tr>
@endforeach
@endforeach
<tr class="total"><td>Total</td><td></td><td class="num m">{!! Pdf::money($section['total']) !!}</td>@if ($previous)<td class="num m">{!! $section['previousTotal'] === null ? '' : Pdf::money($section['previousTotal']) !!}</td>@endif</tr>
@endforeach
</table>
<p class="muted">A firm’s balance sheet in the Schedule III (Division I) format: partners’ funds take the place of shareholders’ funds; loans from partners are repayable on demand.</p>
@include('exports.schedule-notes', ['notes' => $sheet['notes'], 'current' => $current, 'previous' => $previous])
@endsection
