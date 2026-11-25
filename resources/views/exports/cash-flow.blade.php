@php use App\Services\Reports\Exports\Pdf; @endphp
@extends('exports.layout')

@section('content')
<div class="status {{ $flow['reconciled'] ? 'ok' : 'bad' }}">
@if ($flow['reconciled'])
Reconciled. Opening cash <span class="m">{!! Pdf::money($flow['openingCash']) !!}</span> plus the net change <span class="m">{!! Pdf::money($flow['netChange']) !!}</span> equals closing cash <span class="m">{!! Pdf::money($flow['closingCash']) !!}</span>.
@else
Doesn’t reconcile: off by <span class="m">{!! Pdf::money(abs($flow['drift'])) !!}</span>.
@endif
</div>
<table>
<tr><th>Particulars</th><th class="num">Amount</th></tr>
@foreach ([['A. Cash flow from operating activities', $flow['operating'], 'Net cash from operating activities (A)', $flow['operatingTotal']], ['B. Cash flow from investing activities', $flow['investing'], 'Net cash from investing activities (B)', $flow['investingTotal']], ['C. Cash flow from financing activities', $flow['financing'], 'Net cash from financing activities (C)', $flow['financingTotal']]] as [$title, $lines, $label, $total])
<tr><td colspan="2"><b>{{ $title }}</b></td></tr>
@forelse ($lines as $line)
<tr><td style="padding-left: 18px;">{{ $line['label'] }}</td><td class="num m">{!! Pdf::money($line['amount'], true) !!}</td></tr>
@empty
<tr><td style="padding-left: 18px;" class="muted">No activity: equipment is expensed when bought</td><td class="num m">—</td></tr>
@endforelse
<tr class="total"><td>{{ $label }}</td><td class="num m">{!! Pdf::money($total, true) !!}</td></tr>
@endforeach
<tr class="total"><td>Net increase / (decrease) in cash (A + B + C)</td><td class="num m">{!! Pdf::money($flow['netChange'], true) !!}</td></tr>
<tr><td>Cash and cash equivalents at the start</td><td class="num m">{!! Pdf::money($flow['openingCash'], true) !!}</td></tr>
<tr class="total"><td>Cash and cash equivalents at the end</td><td class="num m">{!! Pdf::money($flow['closingCash'], true) !!}</td></tr>
</table>
<p class="muted">Left out because no cash moves: the opening balance (it is the opening cash), the year-end close, personal-card balances converted to loans, and transfers between the sides’ accounts.</p>
@endsection
