<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates a journal entry (series U) when an order is paid.
 *
 * Debit:  payment-method account (1931 Swish, 1933 Bankgiro, 1935 Stripe)
 * Credit: one line per order-line category (3080 Membership, 3050 Event, etc.)
 *
 * Manual and free orders are skipped — no meaningful debit account to use.
 * If no open fiscal year exists the method returns null and logs a notice;
 * the payment itself is not affected.
 */
class OrderJournalService
{
    /** Resolved account objects, keyed by account code. */
    private array $cache = [];

    public function bookOrder(Order $order): ?JournalEntry
    {
        if ($order->total_ore === 0) {
            return null;
        }

        $debitCode = $this->paymentMethodAccount($order->payment_method);
        if (! $debitCode) {
            return null; // manual / free / unknown — skip silently
        }

        $date = ($order->paid_at ?? now())->toDateString();

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            Log::info('OrderJournalService: no open fiscal year, skipping auto-journal', [
                'order_id' => $order->id,
                'date'     => $date,
            ]);
            return null;
        }

        $debitAccount = $this->account($debitCode);

        if (! $debitAccount) {
            Log::warning("OrderJournalService: account {$debitCode} not found in chart", [
                'order_id' => $order->id,
            ]);
            return null;
        }

        $order->loadMissing('lines.orderable');

        // For event lines we need the event's cost center.
        foreach ($order->lines as $line) {
            if ($line->orderable instanceof \App\Models\Events\EventRegistration) {
                $line->orderable->loadMissing('event');
            }
        }

        $credits = $this->resolveCredits($order->lines);

        if ($credits->isEmpty()) {
            Log::warning('OrderJournalService: no credit accounts resolved, skipping', [
                'order_id' => $order->id,
            ]);
            return null;
        }

        return DB::transaction(function () use ($order, $debitAccount, $credits, $date, $fiscalYear) {
            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', 'U')
                ->lockForUpdate()
                ->max('number') + 1;

            $name = $order->user?->member
                ? ($order->user->member->first_name . ' ' . $order->user->member->last_name)
                : ($order->guest_name ?? $order->user?->name ?? '');

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => 'U',
                'number'           => $nextNumber,
                'date'             => $date,
                'description'      => 'Betalning ' . $order->payment_reference
                    . ($name ? ' – ' . $name : ''),
                'source_type'      => Order::class,
                'source_id'        => $order->id,
                'is_locked'        => false,
                'requires_receipt' => false,
            ]);

            $entry->lines()->create([
                'account_id' => $debitAccount->id,
                'debit'      => $order->total_ore,
                'credit'     => 0,
            ]);

            foreach ($credits as [$creditAccount, $amount, $costCenterId]) {
                $entry->lines()->create([
                    'account_id'     => $creditAccount->id,
                    'cost_center_id' => $costCenterId,
                    'debit'          => 0,
                    'credit'         => $amount,
                ]);
            }

            $order->updateQuietly(['journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    // -------------------------------------------------------------------------
    // Mapping helpers
    // -------------------------------------------------------------------------

    /** Returns the chart-of-accounts code for the debit (payment method) side. */
    private function paymentMethodAccount(?string $method): ?string
    {
        return match ($method) {
            Order::METHOD_STRIPE       => '1935',
            Order::METHOD_SWISH        => '1931',
            Order::METHOD_SWISH_HANDEL => '1931',
            Order::METHOD_BANKGIRO     => '1933',
            default                    => null,
        };
    }

    /** Returns the chart-of-accounts code for a given order-line category. */
    private function categoryAccount(string $category): ?string
    {
        return match ($category) {
            OrderLine::CATEGORY_MEMBERSHIP => '3080',
            OrderLine::CATEGORY_EVENT      => '3050',
            OrderLine::CATEGORY_RENTAL     => '3021',
            OrderLine::CATEGORY_TAG        => '3092',
            OrderLine::CATEGORY_SHOP       => '3001',
            default                        => null,
        };
    }

    /**
     * Group order lines by category and resolve each to (Account, amount_ore).
     * Categories without a mapped account are silently dropped.
     * Lines with different cost centers within the same category become separate credit rows.
     *
     * @return Collection<int, array{Account, int, int|null}>
     */
    private function resolveCredits(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderLine $line) => $line->category . '|' . $this->lineCostCenter($line))
            ->map(function ($group, $key) {
                [$category] = explode('|', $key, 2);

                $code = $this->categoryAccount($category);
                if (! $code) {
                    return null;
                }

                $account = $this->account($code);
                if (! $account) {
                    return null;
                }

                $costCenterId = $this->lineCostCenter($group->first());

                return [$account, (int) $group->sum('line_total_ore'), $costCenterId];
            })
            ->filter()
            ->values();
    }

    private function lineCostCenter(OrderLine $line): ?int
    {
        if ($line->category === OrderLine::CATEGORY_EVENT) {
            $event = $line->orderable?->event ?? null;
            return $event?->cost_center_id;
        }

        return null;
    }

    private function account(string $code): ?Account
    {
        return $this->cache[$code] ??= Account::where('code', $code)->first();
    }
}
