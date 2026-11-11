<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(private readonly AccountingReportService $reports) {}

    // GET /accounting/reports/balance-sheet
    public function balanceSheet(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'fiscal_year_id'     => 'required|integer|exists:fiscal_years,id',
            'as_of_date'         => 'required|date_format:Y-m-d',
            'compare_as_of_date' => 'nullable|date_format:Y-m-d',
            'cost_center_id'     => 'nullable|integer|exists:cost_centers,id',
        ]);

        $data = $this->reports->balanceSheet(
            $v['fiscal_year_id'],
            $v['as_of_date'],
            $v['cost_center_id'] ?? null,
            $v['compare_as_of_date'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.balance-sheet', $data, 'balansrakning-' . $v['as_of_date']);
        }

        return response()->json($data);
    }

    // GET /accounting/reports/income-statement
    public function incomeStatement(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'fiscal_year_id'    => 'required|integer|exists:fiscal_years,id',
            'date_from'         => 'required|date_format:Y-m-d',
            'date_to'           => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'compare_date_from' => 'nullable|date_format:Y-m-d',
            'compare_date_to'   => 'nullable|date_format:Y-m-d|after_or_equal:compare_date_from',
            'cost_center_id'    => 'nullable|integer|exists:cost_centers,id',
        ]);

        $data = $this->reports->incomeStatement(
            $v['fiscal_year_id'],
            $v['date_from'],
            $v['date_to'],
            $v['cost_center_id'] ?? null,
            $v['compare_date_from'] ?? null,
            $v['compare_date_to'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.income-statement', $data, 'resultatrakning-' . $v['date_from']);
        }

        return response()->json($data);
    }

    // GET /accounting/reports/general-ledger
    public function generalLedger(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'account_id'     => 'required|integer|exists:accounts,id',
            'date_from'      => 'required|date_format:Y-m-d',
            'date_to'        => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'cost_center_id' => 'nullable|integer|exists:cost_centers,id',
        ]);

        $data = $this->reports->generalLedger(
            $v['fiscal_year_id'],
            $v['account_id'],
            $v['date_from'],
            $v['date_to'],
            $v['cost_center_id'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.general-ledger', $data, 'huvudbok-konto-' . $data['account_code']);
        }

        return response()->json($data);
    }

    // GET /accounting/reports/budget-variance
    public function budgetVariance(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'budget_id'      => 'required|integer|exists:budgets,id',
            'date_from'      => 'required|date_format:Y-m-d',
            'date_to'        => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'cost_center_id' => 'nullable|integer|exists:cost_centers,id',
        ]);

        $data = $this->reports->budgetVariance(
            $v['budget_id'],
            $v['date_from'],
            $v['date_to'],
            $v['cost_center_id'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.budget-variance', $data, 'budgetutfall-' . $v['date_from']);
        }

        return response()->json($data);
    }

    // GET /accounting/reports/journal-list
    public function journalList(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'date_from'      => 'required|date_format:Y-m-d',
            'date_to'        => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'series'         => 'nullable|string|max:5',
            'cost_center_id' => 'nullable|integer|exists:cost_centers,id',
        ]);

        $data = $this->reports->journalList(
            $v['fiscal_year_id'],
            $v['date_from'],
            $v['date_to'],
            $v['series'] ?? null,
            $v['cost_center_id'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.journal-list', $data, 'vernifikatlista-' . $v['date_from']);
        }

        return response()->json($data);
    }

    // GET /accounting/reports/vendor-report
    public function vendorReport(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $v = $request->validate([
            'fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'date_from'      => 'nullable|date_format:Y-m-d',
            'date_to'        => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        $data = $this->reports->vendorReport(
            $v['fiscal_year_id'],
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
        );

        if ($this->wantsPdf($request)) {
            return $this->pdf('pdf.accounting.vendor-report', $data, 'leverantorsrapport-' . $data['date_from']);
        }

        return response()->json($data);
    }

    private function wantsPdf(Request $request): bool
    {
        return $request->query('format') === 'pdf'
            || $request->accepts(['application/pdf']) && ! $request->accepts(['application/json']);
    }

    private function pdf(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', 'portrait')
            ->download($filename . '.pdf');
    }
}
