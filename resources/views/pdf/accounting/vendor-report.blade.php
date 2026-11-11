<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@page { margin: 18mm 14mm; size: A4 portrait; }
body  { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; margin: 0; }
h1   { font-size: 17px; margin: 0 0 2px; }
.meta { color: #555; font-size: 9px; margin-bottom: 14px; }

table { width: 100%; border-collapse: collapse; }
th    { text-align: left; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
        border-bottom: 2px solid #9ca3af; font-size: 9px; }
.th-r { text-align: right; }
td    { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
.r    { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.sub td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Leverantörsrapport</h1>
<p class="meta">Armagedon &nbsp;·&nbsp; {{ $date_from }} – {{ $date_to }}</p>

<table>
  <thead>
    <tr>
      <th style="width:60px">Kod</th>
      <th>Leverantör</th>
      <th class="th-r" style="width:50px">Antal</th>
      <th class="th-r" style="width:90px">Fakturerat</th>
      <th class="th-r" style="width:90px">Betalt</th>
      <th class="th-r" style="width:90px">Obetalt</th>
      <th class="th-r" style="width:60px">Förfallna</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($vendors as $v)
    <tr>
      <td style="font-family:monospace;font-size:9px;">{{ $v['code'] }}</td>
      <td>{{ $v['name'] }}</td>
      <td class="r">{{ $v['invoice_count'] }}</td>
      <td class="r">{{ number_format($v['total_ore']  / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($v['paid_ore']   / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($v['unpaid_ore'] / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ $v['overdue_count'] ?: '' }}</td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="sub">
      <td colspan="2">Totalt {{ count($vendors) }} leverantörer</td>
      <td class="r">{{ $totals['invoice_count'] }}</td>
      <td class="r">{{ number_format($totals['total_ore']  / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($totals['paid_ore']   / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($totals['unpaid_ore'] / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ $totals['overdue_count'] ?: '' }}</td>
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
