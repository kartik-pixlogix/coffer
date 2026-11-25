@php use App\Services\Reports\Exports\Pdf; @endphp
<h2>Notes</h2>
@foreach ($notes as $note)
<table style="margin-bottom: 10px;">
<tr><th colspan="2">{{ $note['number'] }}. {{ $note['title'] }}@if ($note['subtitle'])<br><span class="muted" style="font-weight: 400;">{{ $note['subtitle'] }}</span>@endif</th><th class="num">{{ $current }}</th>@if ($previous)<th class="num">{{ $previous }}</th>@endif</tr>
@forelse ($note['rows'] as $row)
<tr><td class="m" style="width: 60px;">{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="num m">{!! Pdf::money($row['amount'], true) !!}</td>@if ($previous)<td class="num m">{!! $row['previous'] === null ? '' : Pdf::money($row['previous'], true) !!}</td>@endif</tr>
@empty
<tr><td></td><td class="muted">Nothing in this period.</td><td></td>@if ($previous)<td></td>@endif</tr>
@endforelse
<tr class="total"><td></td><td>Total</td><td class="num m">{!! Pdf::money($note['total'], true) !!}</td>@if ($previous)<td class="num m">{!! $note['previousTotal'] === null ? '' : Pdf::money($note['previousTotal'], true) !!}</td>@endif</tr>
</table>
@endforeach
