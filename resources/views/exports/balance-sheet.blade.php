@php use App\Services\Reports\Exports\Pdf; @endphp
@extends('exports.layout')

@section('content')
<div class="status {{ $sheet['balanced'] ? 'ok' : 'bad' }}">
@if ($sheet['balanced'])
Assets = liabilities + equity: <span class="m">{!! Pdf::money($sheet['totalAssets']) !!}</span>.
@else
Off by <span class="m">{!! Pdf::money(abs($sheet['drift'])) !!}</span>: assets <span class="m">{!! Pdf::money($sheet['totalAssets']) !!}</span>, liabilities and equity <span class="m">{!! Pdf::money($sheet['totalLiabilities'] + $sheet['totalEquity']) !!}</span>.
@endif
</div>
<table>
<tr>
<td class="half">
<table>
<tr><th>Liabilities &amp; equity</th><th class="num"></th></tr>
@foreach ($sheet['liabilities'] as $l)
<tr><td>{{ $l['name'] }}</td><td class="num m">{!! Pdf::money($l['amount']) !!}</td></tr>
@endforeach
<tr class="total"><td>Total liabilities</td><td class="num m">{!! Pdf::money($sheet['totalLiabilities']) !!}</td></tr>
@foreach ($sheet['equity'] as $l)
<tr><td>{{ $l['name'] }}</td><td class="num m">{!! Pdf::money($l['amount']) !!}</td></tr>
@endforeach
<tr class="total"><td>Total equity</td><td class="num m">{!! Pdf::money($sheet['totalEquity']) !!}</td></tr>
<tr class="total"><td>Total liabilities &amp; equity</td><td class="num m">{!! Pdf::money($sheet['totalLiabilities'] + $sheet['totalEquity']) !!}</td></tr>
</table>
</td>
<td class="half">
<table>
<tr><th>Assets</th><th class="num"></th></tr>
@foreach ($sheet['assets'] as $l)
<tr><td>{{ $l['name'] }}</td><td class="num m">{!! Pdf::money($l['amount']) !!}</td></tr>
@endforeach
<tr class="total"><td>Total assets</td><td class="num m">{!! Pdf::money($sheet['totalAssets']) !!}</td></tr>
</table>
</td>
</tr>
</table>
<p class="muted">Partner loans sit under liabilities, not equity. Current-year profit is the open years’ income less expenses.</p>
@endsection
