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

table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.th   { text-align: left;  padding: 5px 6px; font-weight: bold; background: #f3f4f6;
        border-bottom: 2px solid #9ca3af; font-size: 9px; }
.th-r { text-align: right; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
        border-bottom: 2px solid #9ca3af; font-size: 9px; white-space: nowrap; }
.group-hdr td { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: .04em;
                color: #6b7280; padding: 8px 6px 2px; border-bottom: 1px solid #e5e7eb; }
td     { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
.r     { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.dim   { color: #9ca3af; text-align: right; }
.sub td { font-weight: bold; background: #f9fafb; border-top: 1px solid #d1d5db; }
.net td { font-weight: bold; font-size: 11px; background: #e5e7eb; border-top: 2px solid #6b7280; }
.pos   { color: #15803d; }
.neg   { color: #b91c1c; }
.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Budgetutfall</h1>
<p class="meta">
  Armagedon &nbsp;·&nbsp; {{ $budget_name }} ({{ $fiscal_year }})
  &nbsp;·&nbsp; {{ $date_from }} – {{ $date_to }}
  @if ($cost_center_id) &nbsp;·&nbsp; Kostnadsställe {{ $cost_center_id }} @endif
</p>
@if (count($last_vouchers))
<p class="vouchers">Senaste ver:
  @foreach ($last_vouchers as $v) {{ $v['last'] }}@if (!$loop->last) &nbsp;·&nbsp; @endif @endforeach
</p>
@endif

@php
  $fmt = fn($ore) => number_format($ore / 100, 2, ',', ' ') . ' kr';
  $sign = fn($ore) => ($ore >= 0 ? '+' : '') . number_format($ore / 100, 2, ',', ' ') . ' kr';
  $cls  = fn($ore) => $ore >= 0 ? 'pos' : 'neg';
@endphp

<table>
  <thead>
    <tr>
      <th class="th" style="width:52px">Konto</th>
      <th class="th">Namn</th>
      <th class="th-r" style="width:88px">Budget</th>
      <th class="th-r" style="width:88px">Utfall</th>
      <th class="th-r" style="width:88px">Avvikelse</th>
      <th class="th-r" style="width:42px">%</th>
    </tr>
  </thead>
  <tbody>

    {{-- Intäkter --}}
    <tr class="group-hdr"><td colspan="6">Intäkter</td></tr>
    @foreach ($income as $row)
    <tr>
      <td style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="r dim">{{ $fmt($row['budget_ore']) }}</td>
      <td class="r">{{ $fmt($row['actual_ore']) }}</td>
      <td class="r {{ $cls($row['variance_ore']) }}">{{ $sign($row['variance_ore']) }}</td>
      <td class="r dim">{{ $row['pct'] !== null ? $row['pct'] . ' %' : '–' }}</td>
    </tr>
    @endforeach
    <tr class="sub">
      <td colspan="2">Summa intäkter</td>
      <td class="r dim">{{ $fmt($totals['income']['budget']) }}</td>
      <td class="r">{{ $fmt($totals['income']['actual']) }}</td>
      <td class="r {{ $cls($totals['income']['variance']) }}">{{ $sign($totals['income']['variance']) }}</td>
      <td></td>
    </tr>

    {{-- Kostnader --}}
    <tr class="group-hdr"><td colspan="6">Kostnader</td></tr>
    @foreach ($expense as $row)
    <tr>
      <td style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="r dim">{{ $fmt($row['budget_ore']) }}</td>
      <td class="r">{{ $fmt($row['actual_ore']) }}</td>
      <td class="r {{ $cls($row['variance_ore']) }}">{{ $sign($row['variance_ore']) }}</td>
      <td class="r dim">{{ $row['pct'] !== null ? $row['pct'] . ' %' : '–' }}</td>
    </tr>
    @endforeach
    <tr class="sub">
      <td colspan="2">Summa kostnader</td>
      <td class="r dim">{{ $fmt($totals['expense']['budget']) }}</td>
      <td class="r">{{ $fmt($totals['expense']['actual']) }}</td>
      <td class="r {{ $cls($totals['expense']['variance']) }}">{{ $sign($totals['expense']['variance']) }}</td>
      <td></td>
    </tr>

  </tbody>
  <tfoot>
    <tr class="net">
      <td colspan="2">Nettoresultat</td>
      <td class="r dim">{{ $fmt($totals['net']['budget']) }}</td>
      <td class="r">{{ $fmt($totals['net']['actual']) }}</td>
      <td class="r {{ $cls($totals['net']['variance']) }}">{{ $sign($totals['net']['variance']) }}</td>
      <td></td>
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
