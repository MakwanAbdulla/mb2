<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\OpeningBalance;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates a SIE 4E export file for the given fiscal year.
 *
 * SIE type 4 includes journal entries (#VER/#TRANS), account definitions,
 * and balance records (#IB/#UB/#RES).
 *
 * Encoding: ISO-8859-1 (declared as PC8 in the #FORMAT record).
 * Amounts:  öre → kronor, period decimal, no thousands separator.
 */
class Sie4Exporter
{
    private const PROGRAM_NAME    = 'Membrum2';
    private const PROGRAM_VERSION = '1.0';

    /** Map our account types to SIE4 KTYP codes. */
    private const TYPE_MAP = [
        Account::TYPE_ASSET     => 'T',
        Account::TYPE_LIABILITY => 'S',
        Account::TYPE_EQUITY    => 'S',
        Account::TYPE_INCOME    => 'I',
        Account::TYPE_EXPENSE   => 'K',
    ];

    public function __construct(private readonly FiscalYear $fiscalYear) {}

    /**
     * Build the full SIE4 file content and return it as a string (ISO-8859-1).
     */
    public function generate(): string
    {
        $lines = [];

        $this->addHeader($lines);
        $this->addAccounts($lines);
        $this->addBalances($lines);
        $this->addJournalEntries($lines);

        $content = implode("\r\n", $lines) . "\r\n";

        return mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Return a filename suitable for the download response.
     */
    public function filename(): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->fiscalYear->name);
        return "SIE4_rakenskapsar_{$name}.se";
    }

    // -------------------------------------------------------------------------
    // Section builders
    // -------------------------------------------------------------------------

    private function addHeader(array &$lines): void
    {
        $fy    = $this->fiscalYear;
        $today = Carbon::today()->format('Ymd');
        $start = $fy->starts_at->format('Ymd');
        $end   = $fy->ends_at->format('Ymd');

        $orgName   = SystemSetting::get('general.organization_name', 'Armagedon Spelförening');
        $orgNumber = SystemSetting::get('general.organization_number', '');

        $lines[] = '#FLAGGA 0';
        $lines[] = '#PROGRAM ' . $this->q(self::PROGRAM_NAME) . ' ' . $this->q(self::PROGRAM_VERSION);
        $lines[] = '#FORMAT PC8';
        $lines[] = "#GEN {$today}";
        $lines[] = '#SIETYP 4';
        $lines[] = '#FNAMN ' . $this->q($orgName);

        if ($orgNumber !== '') {
            $lines[] = '#ORGNR ' . $this->q($orgNumber);
        }

        $lines[] = "#RAR 0 {$start} {$end}";
        $lines[] = '#VALUTA SEK';
        $lines[] = '';
    }

    private function addAccounts(array &$lines): void
    {
        $accounts = Account::active()->leaf()->orderBy('code')->get();

        foreach ($accounts as $account) {
            $code = $account->code;
            $name = $this->q($account->name);
            $lines[] = "#KONTO {$code} {$name}";

            $ktyp = self::TYPE_MAP[$account->type] ?? 'K';
            $lines[] = "#KTYP {$code} {$ktyp}";
        }

        $lines[] = '';
    }

    private function addBalances(array &$lines): void
    {
        $fy = $this->fiscalYear;

        // Accounts that appear in any journal line (current, non-superseded)
        $accountIds = JournalLine::whereNull('superseded_at')
            ->whereHas('journalEntry', fn ($q) => $q->where('fiscal_year_id', $fy->id)
                ->orWhereHas('fiscalYear', fn ($q2) => $q2->where('ends_at', '<', $fy->starts_at)))
            ->distinct()
            ->pluck('account_id');

        $accounts = Account::whereIn('id', $accountIds)->orderBy('code')->get()->keyBy('id');

        // Incoming balances: use explicit opening_balances if entered,
        // otherwise sum all current journal lines before this fiscal year.
        /** @var Collection<int,int> $incoming  account_id → net öre */
        if ($fy->hasOpeningBalances()) {
            $incoming = OpeningBalance::where('fiscal_year_id', $fy->id)
                ->pluck('amount', 'account_id');
        } else {
            $incoming = JournalLine::whereNull('superseded_at')
                ->whereHas('journalEntry', function ($q) use ($fy) {
                    $q->whereHas('fiscalYear', fn ($q2) => $q2->where('ends_at', '<', $fy->starts_at));
                })
                ->select('account_id', DB::raw('SUM(debit) - SUM(credit) as net'))
                ->groupBy('account_id')
                ->pluck('net', 'account_id');
        }

        // Current year movements: current journal lines in this fiscal year
        /** @var Collection<int,int> $movements  account_id → net öre */
        $movements = JournalLine::whereNull('superseded_at')
            ->whereHas('journalEntry', fn ($q) => $q->where('fiscal_year_id', $fy->id))
            ->select('account_id', DB::raw('SUM(debit) - SUM(credit) as net'))
            ->groupBy('account_id')
            ->pluck('net', 'account_id');

        // All account IDs that appear in either collection
        $allIds = $incoming->keys()->merge($movements->keys())->unique();

        foreach ($allIds->sort() as $accountId) {
            /** @var Account|null $account */
            $account = $accounts->get($accountId);
            if (! $account) {
                continue;
            }

            $code = $account->code;
            $ib   = (int) ($incoming->get($accountId, 0));
            $move = (int) ($movements->get($accountId, 0));
            $ub   = $ib + $move;

            if ($account->isIncome() || $account->isExpense()) {
                // Income/expense: #RES only (no prior-year carry-forward)
                if ($move !== 0) {
                    $lines[] = "#RES 0 {$code} " . $this->amount($move);
                }
            } else {
                // Balance sheet: #IB and #UB
                if ($ib !== 0) {
                    $lines[] = "#IB 0 {$code} " . $this->amount($ib);
                }
                if ($ub !== 0) {
                    $lines[] = "#UB 0 {$code} " . $this->amount($ub);
                }
            }
        }

        $lines[] = '';
    }

    private function addJournalEntries(array &$lines): void
    {
        $entries = JournalEntry::with(['currentLines.account'])
            ->where('fiscal_year_id', $this->fiscalYear->id)
            ->orderBy('date')
            ->orderBy('series')
            ->orderBy('number')
            ->get();

        foreach ($entries as $entry) {
            $series = $entry->series ?? 'A';
            $number = $entry->number;
            $date   = Carbon::parse($entry->date)->format('Ymd');
            $desc   = $this->q($entry->description ?? '');

            $lines[] = "#VER {$series} {$number} {$date} {$desc}";
            $lines[] = '{';

            foreach ($entry->currentLines as $line) {
                $code   = $line->account->code ?? '9999';
                $net    = $line->debit - $line->credit;
                $amount = $this->amount($net);
                $tdesc  = $this->q($line->description ?? '');
                $lines[] = "#TRANS {$code} {} {$amount} {$date} {$tdesc}";
            }

            $lines[] = '}';
        }
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    /**
     * Quote a SIE4 string: wrap in double quotes, escape backslash and double-quote.
     */
    private function q(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * Format an öre integer as a SIE4 amount (kronor, period decimal).
     * e.g. 125050 → "1250.50"
     */
    private function amount(int $ore): string
    {
        $kronor  = intdiv(abs($ore), 100);
        $cents   = abs($ore) % 100;
        $signed  = ($ore < 0 ? '-' : '') . $kronor . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        return $signed;
    }
}
