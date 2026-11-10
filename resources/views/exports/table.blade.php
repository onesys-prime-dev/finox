<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm 10mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #111; }
    h1 { font-size: 14pt; margin: 0 0 2pt; }
    .meta { color: #555; margin-bottom: 8pt; font-size: 8pt; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eee; text-align: left; padding: 3pt 4pt; border-bottom: 1px solid #999; }
    td { padding: 3pt 4pt; border-bottom: 1px solid #ddd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .note { margin-top: 8pt; color: #a00; }
</style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="meta">{{ $org }} &middot; Generated {{ now()->format('M d, Y H:i') }} by {{ $by }} &middot; {{ number_format($total) }} row(s)</div>
<table>
    <thead><tr>@foreach($headers as $i => $h)<th @if(in_array($i, $numeric)) class="num" @endif>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($rows as $r)
        <tr>@foreach(array_values($r) as $i => $v)<td @if(in_array($i, $numeric)) class="num" @endif>{{ is_numeric($v) && in_array($i, $numeric) ? number_format((float) $v, 2) : $v }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
@if($total > count($rows))<div class="note">Showing the first {{ number_format(count($rows)) }} of {{ number_format($total) }} rows. Use Export Excel or CSV for the full set.</div>@endif
</body>
</html>
