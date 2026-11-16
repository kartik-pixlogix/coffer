@php use App\Services\Reports\Exports\Pdf; @endphp
@extends('exports.layout')

@section('content')
<table class="kpis">
<tr>
<td><div class="label">Revenue</div><div class="fig m">{!! Pdf::money($report['kpis']['income']) !!}</div><div class="muted">{{ $report['kpis']['months'] }} {{ $report['kpis']['months'] === 1 ? 'month' : 'months' }}</div></td>
<td><div class="label">Expenses</div><div class="fig m">{!! Pdf::money($report['kpis']['expenses']) !!}</div><div class="muted">incl. <span class="m">{!! Pdf::money($report['kpis']['salary']) !!}</span> salaries</div></td>
<td><div class="label">Other expenses</div><div class="fig m">{!! Pdf::money($report['kpis']['other']) !!}</div><div class="muted">Everything except salaries</div></td>
<td class="lime"><div class="label">Net profit</div><div class="fig m">{!! Pdf::money($report['kpis']['net']) !!}</div><div>{{ Pdf::percent($report['kpis']['marginBps']) }} margin</div></td>
</tr>
</table>

<h2>Ratios</h2>
<table>
<tr><th>Profit margin</th><th>Expense ratio</th><th>Avg monthly revenue</th><th>Avg monthly expense</th></tr>
<tr>
<td class="m">{{ Pdf::percent($report['kpis']['marginBps']) }}</td>
<td class="m">{{ Pdf::percent($report['kpis']['expenseRatioBps']) }}</td>
<td class="m">{!! Pdf::money($report['kpis']['avgIncome']) !!}</td>
<td class="m">{!! Pdf::money($report['kpis']['avgExpenses']) !!}</td>
</tr>
</table>

<h2>Month by month</h2>
<table>
<tr><th>Month</th><th class="num">Revenue</th><th class="num">Expenses</th><th class="num">Other</th><th class="num">Salaries</th><th class="num">Profit</th><th class="num">Margin</th></tr>
@foreach ($report['months'] as $m)
<tr>
<td>{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $m['month'])->format('F Y') }}</td>
<td class="num m">{!! Pdf::money($m['income']) !!}</td>
<td class="num m">{!! Pdf::money($m['expenses']) !!}</td>
<td class="num m">{!! Pdf::money($m['other']) !!}</td>
<td class="num m">{!! Pdf::money($m['salary']) !!}</td>
<td class="num m {{ $m['net'] < 0 ? 'neg' : '' }}">{!! Pdf::money($m['net']) !!}</td>
<td class="num m {{ $m['net'] < 0 ? 'neg' : '' }}">{{ Pdf::percent($m['marginBps']) }}</td>
</tr>
@endforeach
<tr class="total">
<td>Total</td>
<td class="num m">{!! Pdf::money($report['totals']['income']) !!}</td>
<td class="num m">{!! Pdf::money($report['totals']['expenses']) !!}</td>
<td class="num m">{!! Pdf::money($report['totals']['other']) !!}</td>
<td class="num m">{!! Pdf::money($report['totals']['salary']) !!}</td>
<td class="num m {{ $report['totals']['net'] < 0 ? 'neg' : '' }}">{!! Pdf::money($report['totals']['net']) !!}</td>
<td class="num m">{{ Pdf::percent($report['totals']['marginBps']) }}</td>
</tr>
</table>

<h2>Partner P&amp;L</h2>
<table>
<tr><th>Partner</th><th class="num">Equity</th><th class="num">Revenue generated</th><th class="num">Expenses</th><th class="num">Other</th><th class="num">Net contribution</th><th class="num">Profit due</th><th class="num">Revenue share</th></tr>
@foreach ($report['partners'] as $p)
<tr>
<td>{{ $p['name'] }}@if ($p['side'])<br><span class="muted">{{ $p['side'] }}</span>@endif</td>
<td class="num m">{{ Pdf::percent($p['equityBps']) }}</td>
<td class="num m">{!! Pdf::money($p['revenue']) !!}</td>
<td class="num m">{!! Pdf::money($p['expenses']) !!}</td>
<td class="num m">{!! Pdf::money($p['other']) !!}</td>
<td class="num m {{ $p['net'] < 0 ? 'neg' : '' }}">{!! Pdf::money($p['net']) !!}</td>
<td class="num m">{!! Pdf::money($p['profitDue']) !!}</td>
<td class="num m">{{ Pdf::percent($p['revenueShareBps']) }}</td>
</tr>
@endforeach
<tr class="total">
<td>Total</td>
<td class="num m">{{ Pdf::percent($report['partnerTotals']['equityBps']) }}</td>
<td class="num m">{!! Pdf::money($report['partnerTotals']['revenue']) !!}</td>
<td class="num m">{!! Pdf::money($report['partnerTotals']['expenses']) !!}</td>
<td class="num m">{!! Pdf::money($report['partnerTotals']['other']) !!}</td>
<td class="num m">{!! Pdf::money($report['partnerTotals']['net']) !!}</td>
<td class="num m">{!! Pdf::money($report['partnerTotals']['profitDue']) !!}</td>
<td class="num m">{{ $report['totals']['income'] === 0 ? '—' : '100.0%' }}</td>
</tr>
</table>
<p class="muted">{{ $note }}</p>
@endsection
