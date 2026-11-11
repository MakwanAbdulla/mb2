<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@page { margin: 18mm 14mm; size: A4 landscape; }
body  { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; margin: 0; }
h1   { font-size: 17px; margin: 0 0 2px; }
.meta { color: #555; font-size: 9px; margin-bottom: 4px; }
.vouchers { font-size: 8px; color: #888; margin-bottom: 14px; }

table { width: 100%; border-collapse: collapse; }
th    { text-align: left; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
        border-bottom: 2px solid #9ca3af; font-size: 9px; }
.th-r { text-align: right; }
td    { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
.r    { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.ib td { color: #9ca3af; font-style: italic; border-bottom: 1px dashed #d1d5db; }
.sub td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Huvudbok — {{ $account_code }} {{ $account_name }}</h1>
<p class="meta">
  Armagedon &nbsp;·&nbsp; {{ $date_from }} – {{ $date_to }}
  @if ($cost_center_id) &nbsp;·&nbsp; Kostnadsställe {{ $cost_center_id }} @endif
</p>
@if (count($last_vouchers))
<p class="vouchers">Senaste ver:
  @foreach ($last_vouchers as $v) {{ $v['last'] }}@if (!$loop->last) &nbsp;·&nbsp; @endif @endforeach
</p>
@endif

@php $fmt = fn($ore) => $ore ? number_format($ore / 100, 2, ',', ' ') : ''; @endphp

<table>
  <thead>
    <tr>
      <th style="width:68px">Datum</th>
      <th style="width:48px">Ver</th>
      <th>Beskrivning</th>
      <th class="th-r" style="width:90px">Debet</th>
      <th class="th-r" style="width:90px">Kredit</th>
      <th class="th-r" style="width:96px">Saldo</th>
    </tr>
  </thead>
  <tbody>
    <tr class="ib">
      <td>{{ $date_from }}</td>
      <td></td>
      <td>Ingående saldo</td>
      <td class="r"></td>
      <td class="r"></td>
      <td class="r">{{ number_format($opening_balance / 100, 2, ',', ' ') }} kr</td>
    </tr>
    @foreach ($rows as $row)
    <tr>
      <td>{{ $row['date'] }}</td>
      <td style="font-family:monospace;font-size:9px;">{{ $row['ver'] }}</td>
      <td>{{ $row['description'] }}</td>
      <td class="r">{{ $fmt($row['debit']) }}</td>
      <td class="r">{{ $fmt($row['credit']) }}</td>
      <td class="r">{{ number_format($row['balance'] / 100, 2, ',', ' ') }} kr</td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="sub">
      <td colspan="3">Summa period</td>
      <td class="r">{{ number_format($period_totals['debit']   / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($period_totals['credit']  / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($period_totals['closing'] / 100, 2, ',', ' ') }} kr</td>
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
