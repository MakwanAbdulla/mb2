<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@page { margin: 18mm 14mm; }
body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 0; }
h1   { font-size: 17px; margin: 0 0 2px; }
.meta { color: #555; font-size: 9px; margin-bottom: 4px; }
.vouchers { font-size: 8px; color: #888; margin-bottom: 14px; }
.warn { font-size: 9px; color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; padding: 4px 8px; margin-bottom: 10px; }

table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.th-label { text-align: left; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
            border-bottom: 2px solid #9ca3af; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
.th-amt   { text-align: right; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
            border-bottom: 2px solid #9ca3af; white-space: nowrap; }
.group-hdr td { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: .04em;
                color: #6b7280; padding: 7px 6px 2px; border-bottom: 1px solid #e5e7eb; }
td        { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
.amt      { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.subtotal td { font-weight: bold; background: #f9fafb; border-top: 1px solid #d1d5db; border-bottom: 2px solid #d1d5db; }
.grandtotal td { font-weight: bold; font-size: 11px; background: #e5e7eb; border-top: 2px solid #6b7280; }
.result-row td { font-style: italic; color: #374151; }
.neg { color: #b91c1c; }

.col-code { width: 52px; }
.col-amt  { width: 90px; }

.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Balansräkning</h1>
<p class="meta">
  Armagedon &nbsp;·&nbsp; Per <strong>{{ $as_of_date }}</strong>
  @if ($compare_as_of_date) &nbsp;(jfr {{ $compare_as_of_date }}) @endif
  @if ($cost_center_id) &nbsp;·&nbsp; Kostnadsställe {{ $cost_center_id }} @endif
</p>
@if (count($last_vouchers))
<p class="vouchers">Senaste ver:
  @foreach ($last_vouchers as $v) {{ $v['last'] }}@if (!$loop->last) &nbsp;·&nbsp; @endif @endforeach
</p>
@endif

@if (!$balanced)
<div class="warn">⚠ Balansen stämmer inte — differens {{ number_format(abs($totals['assets'] - $totals['liabilities']) / 100, 2, ',', ' ') }} kr.</div>
@endif

@php
  $hasCmp = $compare_as_of_date !== null;
  $fmt = fn($ore) => $ore !== null ? number_format($ore / 100, 2, ',', ' ') . ' kr' : '–';
@endphp

{{-- ── TILLGÅNGAR ── --}}
<table>
  <thead>
    <tr>
      <th class="th-label col-code">Konto</th>
      <th class="th-label">Namn</th>
      <th class="th-amt col-amt">{{ $as_of_date }}</th>
      @if ($hasCmp) <th class="th-amt col-amt">{{ $compare_as_of_date }}</th> @endif
    </tr>
  </thead>
  <tbody>
    @foreach ($asset_groups as $group)
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">{{ $group['label'] }}</td></tr>
    @foreach ($group['rows'] as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt {{ $row['net'] < 0 ? 'neg' : '' }}">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    <tr class="subtotal">
      <td colspan="2">Summa {{ strtolower($group['label']) }}</td>
      <td class="amt">{{ $fmt($group['total']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($group['total_compare']) }}</td> @endif
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="grandtotal">
      <td colspan="2">SUMMA TILLGÅNGAR</td>
      <td class="amt">{{ $fmt($totals['assets']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($totals['assets_compare']) }}</td> @endif
    </tr>
  </tfoot>
</table>

{{-- ── EGET KAPITAL OCH SKULDER ── --}}
<table>
  <thead>
    <tr>
      <th class="th-label col-code">Konto</th>
      <th class="th-label">Namn</th>
      <th class="th-amt col-amt">{{ $as_of_date }}</th>
      @if ($hasCmp) <th class="th-amt col-amt">{{ $compare_as_of_date }}</th> @endif
    </tr>
  </thead>
  <tbody>
    {{-- Eget kapital --}}
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">{{ $equity_group['label'] }}</td></tr>
    @foreach ($equity_group['rows'] as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt {{ $row['net'] < 0 ? 'neg' : '' }}">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    <tr class="result-row">
      <td class="col-code" style="font-family:monospace;font-size:9px;">–</td>
      <td>Årets resultat</td>
      <td class="amt {{ $equity_group['result'] < 0 ? 'neg' : '' }}">{{ $fmt($equity_group['result']) }}</td>
      @if ($hasCmp) <td class="amt {{ ($equity_group['result_compare'] ?? 0) < 0 ? 'neg' : '' }}">{{ $fmt($equity_group['result_compare']) }}</td> @endif
    </tr>
    <tr class="subtotal">
      <td colspan="2">Summa eget kapital</td>
      <td class="amt">{{ $fmt($equity_group['total']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($equity_group['total_compare']) }}</td> @endif
    </tr>

    {{-- Skulder --}}
    @foreach ($liability_groups as $group)
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">{{ $group['label'] }}</td></tr>
    @foreach ($group['rows'] as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    <tr class="subtotal">
      <td colspan="2">Summa {{ strtolower($group['label']) }}</td>
      <td class="amt">{{ $fmt($group['total']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($group['total_compare']) }}</td> @endif
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="grandtotal">
      <td colspan="2">SUMMA EGET KAPITAL OCH SKULDER</td>
      <td class="amt">{{ $fmt($totals['liabilities']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($totals['liabilities_compare']) }}</td> @endif
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
