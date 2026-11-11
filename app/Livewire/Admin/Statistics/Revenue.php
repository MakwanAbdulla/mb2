<?php

namespace App\Livewire\Admin\Statistics;

use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Revenue extends Component
{
    #[Url(as: 'ar')]
    public int $year = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('statistics.view'), 403);
        if ($this->year < 2000) {
            $this->year = now()->year;
        }
    }

    public function render()
    {
        $year      = $this->year;
        $prevYear  = $year - 1;
        $yearStart = Carbon::createFromDate($year,     1, 1)->startOfDay();
        $yearEnd   = Carbon::createFromDate($year,    12, 31)->endOfDay();
        $prevStart = Carbon::createFromDate($prevYear, 1, 1)->startOfDay();
        $prevEnd   = Carbon::createFromDate($prevYear, 12, 31)->endOfDay();

        // Paid orders this year/last year
        $paidThisYear = Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [$yearStart, $yearEnd])
            ->pluck('id');

        $paidLastYear = Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [$prevStart, $prevEnd])
            ->pluck('id');

        $totalOreThisYear = OrderLine::whereIn('order_id', $paidThisYear)->sum('line_total_ore');
        $totalOreLastYear = OrderLine::whereIn('order_id', $paidLastYear)->sum('line_total_ore');

        $totalOrders      = $paidThisYear->count();
        $totalOrdersPrev  = $paidLastYear->count();

        // Revenue by category
        $categoryLabels = [
            OrderLine::CATEGORY_MEMBERSHIP => 'Medlemsavgifter',
            OrderLine::CATEGORY_EVENT      => 'Evenemang',
            OrderLine::CATEGORY_SHOP       => 'Butik',
            OrderLine::CATEGORY_RENTAL     => 'Hyror',
            OrderLine::CATEGORY_TAG        => 'RFID-taggar',
        ];

        $linesThisYear = OrderLine::whereIn('order_id', $paidThisYear)
            ->select('category', DB::raw('SUM(line_total_ore) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        $linesLastYear = OrderLine::whereIn('order_id', $paidLastYear)
            ->select('category', DB::raw('SUM(line_total_ore) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        $categoryStats = collect($categoryLabels)->map(fn ($label, $category) => [
            'label'        => $label,
            'line_total_ore'    => (int) $linesThisYear->get($category, 0),
            'prev_ore'     => (int) $linesLastYear->get($category, 0),
            'formatted'    => number_format((int) $linesThisYear->get($category, 0) / 100, 2, ',', ' ') . ' kr',
        ])->filter(fn ($c) => $c['line_total_ore'] > 0 || $c['prev_ore'] > 0)->values();

        // Payment method breakdown
        $methodLabels = [
            Order::METHOD_STRIPE       => 'Kortbetalning',
            Order::METHOD_SWISH_HANDEL => 'Swish Handel',
            Order::METHOD_SWISH        => 'Swish',
            Order::METHOD_BANKGIRO     => 'Bankgiro',
            Order::METHOD_MANUAL       => 'Manuell',
            Order::METHOD_FREE         => 'Gratis',
        ];

        $methodStats = Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [$yearStart, $yearEnd])
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_ore) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'label'     => $methodLabels[$row->payment_method] ?? $row->payment_method,
                'count'     => $row->count,
                'total_ore' => $row->total,
                'formatted' => number_format($row->total / 100, 2, ',', ' ') . ' kr',
            ])
            ->sortByDesc('total_ore')
            ->values();

        // Monthly revenue
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

        $monthlyThisYear = Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [$yearStart, $yearEnd])
            ->select(DB::raw('MONTH(paid_at) as month'), DB::raw('SUM(total_ore) as total'))
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyLastYear = Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [$prevStart, $prevEnd])
            ->select(DB::raw('MONTH(paid_at) as month'), DB::raw('SUM(total_ore) as total'))
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthly = collect(range(1, 12))->map(fn ($m) => [
            'label'   => $monthNames[$m - 1],
            'current' => (int) $monthlyThisYear->get($m, 0),
            'prev'    => (int) $monthlyLastYear->get($m, 0),
        ]);

        // Format totals
        $totalFormatted     = number_format($totalOreThisYear / 100, 2, ',', ' ') . ' kr';
        $totalPrevFormatted = number_format($totalOreLastYear / 100, 2, ',', ' ') . ' kr';

        // Year selector
        $earliest  = Order::where('status', Order::STATUS_PAID)->min('paid_at');
        $firstYear = $earliest ? Carbon::parse($earliest)->year : $year;
        $years     = array_reverse(range(max($firstYear, $year - 5), now()->year));

        return view('livewire.admin.statistics.revenue', compact(
            'year', 'prevYear',
            'totalOreThisYear', 'totalOreLastYear',
            'totalFormatted', 'totalPrevFormatted',
            'totalOrders', 'totalOrdersPrev',
            'categoryStats', 'methodStats', 'monthly',
            'years',
        ));
    }
}
