{{-- Shared frame for PDF exports (dompdf). Brand fonts are embedded from resources/fonts (D61, built by scripts/pdf-fonts.mjs):
     Plus Jakarta Sans for text, IBM Plex Mono for money. The latin files have no ₹, so the
     sign is set in the latin-ext cut (see App\Services\Reports\Exports\Pdf::money). --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
@font-face { font-family: 'Coffer Sans'; font-weight: 400; src: url('{{ $fonts['sans'] }}') format('truetype'); }
@font-face { font-family: 'Coffer Sans'; font-weight: 600; src: url('{{ $fonts['sansBold'] }}') format('truetype'); }
@font-face { font-family: 'Coffer Sans Ext'; font-weight: 400; src: url('{{ $fonts['sansExt'] }}') format('truetype'); }
@font-face { font-family: 'Coffer Mono'; font-weight: 400; src: url('{{ $fonts['mono'] }}') format('truetype'); }
@font-face { font-family: 'Coffer Mono'; font-weight: 600; src: url('{{ $fonts['monoBold'] }}') format('truetype'); }
@font-face { font-family: 'Coffer Mono Ext'; font-weight: 400; src: url('{{ $fonts['monoExt'] }}') format('truetype'); }
@page { margin: 28px 32px 40px; }
body { font-family: 'Coffer Sans', 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 14px; color: #1c1c1e; }
h1 { font-size: 20px; line-height: 24px; font-weight: 600; margin: 0; }
h2 { font-size: 12px; line-height: 16px; font-weight: 600; margin: 18px 0 6px; }
.kicker { color: #5f5f66; font-size: 10px; margin: 0 0 4px; }
.meta { color: #5f5f66; margin: 4px 0 14px; }
.brand { float: right; text-align: right; color: #5f5f66; }
.brand b { display: block; color: #1c1c1e; font-size: 14px; font-weight: 600; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-weight: 600; color: #5f5f66; border-bottom: 1px solid #d4d5da; padding: 5px 6px; font-size: 9px; }
td { padding: 5px 6px; border-bottom: 1px solid #e4e5e9; vertical-align: top; }
.num { text-align: right; white-space: nowrap; }
.m { font-family: 'Coffer Mono', 'DejaVu Sans Mono', monospace; }
.r { font-family: 'Coffer Mono Ext', 'DejaVu Sans Mono', monospace; font-weight: 400 !important; }
.rs { font-family: 'Coffer Sans Ext', 'DejaVu Sans', sans-serif; }
.neg { color: #b42318; }
tr.total td { font-weight: 600; border-top: 1px solid #d4d5da; border-bottom: 0; }
.muted { color: #5f5f66; }
.kpis td { border: 1px solid #e4e5e9; padding: 8px 10px; width: 25%; }
.kpis .label { color: #5f5f66; font-size: 9px; }
.kpis .fig { font-size: 15px; line-height: 20px; }
.lime { background: #d6fe68; }
.status { padding: 8px 10px; border: 1px solid #e4e5e9; margin: 0 0 10px; }
.status.ok { background: #f3ffd6; }
.status.bad { background: #fef0c7; }
.half { width: 49%; vertical-align: top; }
.foot { position: fixed; bottom: -24px; left: 0; right: 0; color: #85868c; font-size: 8px; }
</style>
</head>
<body>
<div class="foot">{{ $company }} · Generated {{ $generated }} · Figures from the Coffer ledger</div>
<div class="brand">@if (! empty($logo))<img src="{{ $logo }}" alt="" style="height: 32px; margin-bottom: 4px;"><br>@endif<b>Coffer</b>{{ $company }}</div>
<p class="kicker">{{ $kicker }}</p>
<h1>{{ $title }}</h1>
<p class="meta">{{ $meta }}</p>
@yield('content')
</body>
</html>
