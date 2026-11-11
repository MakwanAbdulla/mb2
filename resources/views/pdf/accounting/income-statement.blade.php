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

table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
.th-label { text-align: left; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
            border-bottom: 2px solid #9ca3af; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
.th-amt   { text-align: right; padding: 5px 6px; font-weight: bold; background: #f3f4f6;
            border-bottom: 2px solid #9ca3af; white-space: nowrap; }
.group-hdr td { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: .04em;
                color: #6b7280; padding: 8px 6px 2px; border-bottom: 1px solid #e5e7eb; }
td        { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
.amt      { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.subtotal td { font-weight: bold; background: #f9fafb; border-top: 1px solid #d1d5db; }
.delsumma td { font-weight: bold; font-size: 10.5px; background: #eff6ff;
               border-top: 2px solid #93c5fd; border-bottom: 2px solid #93c5fd; }
.resultat td { font-weight: bold; font-size: 11px; background: #e5e7eb;
               border-top: 2px solid #6b7280; }
.neg { color: #b91c1c; }
.col-code { width: 52px; }
.col-amt  { width: 90px; }
.footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: right;
          border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<h1>Resultaträkning</h1>
<p class="meta">
  Armagedon &nbsp;·&nbsp; <strong>{{ $date_from }}</strong> – <strong>{{ $date_to }}</strong>
  @if ($compare_date_from) &nbsp;(jfr {{ $compare_date_from }} – {{ $compare_date_to }}) @endif
  @if ($cost_center_id) &nbsp;·&nbsp; Kostnadsställe {{ $cost_center_id }} @endif
</p>
@if (count($last_vouchers))
<p class="vouchers">Senaste ver:
  @foreach ($last_vouchers as $v) {{ $v['last'] }}@if (!$loop->last) &nbsp;·&nbsp; @endif @endforeach
</p>
@endif

@php
  $hasCmp = $compare_date_from !== null;
  $fmt    = fn($ore) => $ore !== null ? number_format($ore / 100, 2, ',', ' ') . ' kr' : '–';
  $label1 = $date_from . ' – ' . $date_to;
  $label2 = $hasCmp ? $compare_date_from . ' – ' . $compare_date_to : '';
@endphp

<table>
  <thead>
    <tr>
      <th class="th-label col-code">Konto</th>
      <th class="th-label">Namn</th>
      <th class="th-amt col-amt">{{ $label1 }}</th>
      @if ($hasCmp) <th class="th-amt col-amt">{{ $label2 }}</th> @endif
    </tr>
  </thead>
  <tbody>

    {{-- Rörelsens intäkter --}}
    @if (count($op_income))
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">Rörelsens intäkter</td></tr>
    @foreach ($op_income as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt {{ $row['net'] < 0 ? 'neg' : '' }}">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    <tr class="subtotal">
      <td colspan="2">Summa rörelsens intäkter</td>
      <td class="amt">{{ $fmt($totals['op_income']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($totals['op_income_compare']) }}</td> @endif
    </tr>
    @endif

    {{-- Rörelsekostnader --}}
    @if (count($op_expense))
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">Rörelsekostnader</td></tr>
    @foreach ($op_expense as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    <tr class="subtotal">
      <td colspan="2">Summa rörelsekostnader</td>
      <td class="amt">{{ $fmt($totals['op_expense']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($totals['op_expense_compare']) }}</td> @endif
    </tr>
    @endif

  </tbody>
  <tfoot>
    <tr class="delsumma">
      <td colspan="2">Rörelseresultat</td>
      <td class="amt {{ $totals['op_result'] < 0 ? 'neg' : '' }}">{{ $fmt($totals['op_result']) }}</td>
      @if ($hasCmp) <td class="amt {{ ($totals['op_result_compare'] ?? 0) < 0 ? 'neg' : '' }}">{{ $fmt($totals['op_result_compare']) }}</td> @endif
    </tr>
  </tfoot>
</table>

{{-- Finansiella poster (visas bara om de finns) --}}
@if (count($financial_income) || count($financial_expense))
<table>
  <tbody>
    @if (count($financial_income))
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">Finansiella intäkter</td></tr>
    @foreach ($financial_income as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    @endif
    @if (count($financial_expense))
    <tr class="group-hdr"><td colspan="{{ $hasCmp ? 4 : 3 }}">Finansiella kostnader</td></tr>
    @foreach ($financial_expense as $row)
    <tr>
      <td class="col-code" style="font-family:monospace;font-size:9px;">{{ $row['code'] }}</td>
      <td>{{ $row['name'] }}</td>
      <td class="amt">{{ $fmt($row['net']) }}</td>
      @if ($hasCmp) <td class="amt">{{ $fmt($row['net_compare']) }}</td> @endif
    </tr>
    @endforeach
    @endif
  </tbody>
  <tfoot>
    <tr class="subtotal">
      <td colspan="2">Finansnetto</td>
      <td class="amt {{ $totals['financial_net'] < 0 ? 'neg' : '' }}">{{ $fmt($totals['financial_net']) }}</td>
      @if ($hasCmp) <td class="amt {{ ($totals['financial_net_compare'] ?? 0) < 0 ? 'neg' : '' }}">{{ $fmt($totals['financial_net_compare']) }}</td> @endif
    </tr>
  </tfoot>
</table>
@endif

<table>
  <tfoot>
    <tr class="resultat">
      <td colspan="2">ÅRETS RESULTAT</td>
      <td class="amt {{ $totals['result'] < 0 ? 'neg' : '' }}">{{ $fmt($totals['result']) }}</td>
      @if ($hasCmp) <td class="amt {{ ($totals['result_compare'] ?? 0) < 0 ? 'neg' : '' }}">{{ $fmt($totals['result_compare']) }}</td> @endif
    </tr>
  </tfoot>
</table>

<p class="footer">Genererad {{ now()->format('d/m Y H:i') }}</p>
</body>
</html>
