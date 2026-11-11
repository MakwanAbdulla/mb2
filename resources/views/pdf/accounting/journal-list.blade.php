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
.sub td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Verifikatlista</h1>
<p class="meta">
  Armagedon &nbsp;·&nbsp; {{ $date_from }} – {{ $date_to }}
  @if ($series) &nbsp;·&nbsp; Serie {{ $series }} @endif
  @if ($cost_center_id) &nbsp;·&nbsp; Kostnadsställe {{ $cost_center_id }} @endif
</p>
@if (count($last_vouchers))
<p class="vouchers">Senaste ver:
  @foreach ($last_vouchers as $v) {{ $v['last'] }}@if (!$loop->last) &nbsp;·&nbsp; @endif @endforeach
</p>
@endif

<table>
  <thead>
    <tr>
      <th style="width:68px">Datum</th>
      <th style="width:48px">Ver</th>
      <th>Beskrivning</th>
      <th class="th-r" style="width:90px">Debet</th>
      <th class="th-r" style="width:90px">Kredit</th>
      <th class="th-r" style="width:30px">Bil.</th>
      <th style="width:100px">Skapad av</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($entries as $entry)
    <tr>
      <td>{{ $entry['date'] }}</td>
      <td style="font-family:monospace;font-size:9px;">{{ $entry['ver'] }}</td>
      <td>{{ $entry['description'] }}</td>
      <td class="r">{{ number_format($entry['total_debit']  / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($entry['total_credit'] / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ $entry['documents_count'] ?: '' }}</td>
      <td>{{ $entry['created_by'] }}</td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="sub">
      <td colspan="3">Totalt {{ count($entries) }} verifikat</td>
      <td class="r">{{ number_format($grand_totals['debit']  / 100, 2, ',', ' ') }} kr</td>
      <td class="r">{{ number_format($grand_totals['credit'] / 100, 2, ',', ' ') }} kr</td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
