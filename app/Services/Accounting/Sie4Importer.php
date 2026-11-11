<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\OpeningBalance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Parses and imports SIE 4 files.
 *
 * Encoding: auto-detected (UTF-8 BOM → UTF-8 → ISO-8859-1 fallback).
 * Amounts: SIE4 uses decimal kronor; we store integer öre.
 */
class Sie4Importer
{
    // -------------------------------------------------------------------------
    // Encoding
    // -------------------------------------------------------------------------

    /**
     * Detect encoding and return content as UTF-8.
     * Handles: UTF-8 BOM, valid UTF-8 without BOM, and ISO-8859-1 (SIE4 standard).
     */
    public static function decodeToUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse SIE4 UTF-8 content into a structured document.
     *
     * @return array{
     *   org_name: string,
     *   org_number: string,
     *   program: string,
     *   fiscal_years: array<int, array{start: string, end: string}>,
     *   accounts: array<string, array{name: string, ktyp: string|null}>,
     *   opening_balances: array<int, array<string, int>>,
     *   closing_balances: array<int, array<string, int>>,
     *   result_amounts: array<int, array<string, int>>,
     *   entries: list<array{series: string, number: int, date: string, desc: string, lines: list<array{code: string, amount: int, desc: string}>}>,
     * }
     */
    public function parse(string $utf8Content): array
    {
        $doc = [
            'org_name'         => '',
            'org_number'       => '',
            'program'          => '',
            'fiscal_years'     => [],
            'accounts'         => [],
            'opening_balances' => [],
            'closing_balances' => [],
            'result_amounts'   => [],
            'entries'          => [],
        ];

        $lines = preg_split('/\r?\n/', $utf8Content);
        $i     = 0;
        $n     = \count($lines);

        while ($i < $n) {
            $line = trim($lines[$i]);
            $i++;

            if ($line === '' || $line[0] !== '#') {
                continue;
            }

            $spacePos = strpos($line, ' ');
            $keyword  = $spacePos !== false
                ? strtoupper(substr($line, 1, $spacePos - 1))
                : strtoupper(substr($line, 1));
            $rest     = $spacePos !== false ? substr($line, $spacePos + 1) : '';
            $tokens   = $this->tokenize($rest);

            switch ($keyword) {
                case 'FNAMN':
                    $doc['org_name'] = $tokens[0] ?? '';
                    break;

                case 'ORGNR':
                    $doc['org_number'] = $tokens[0] ?? '';
                    break;

                case 'PROGRAM':
                    $doc['program'] = implode(' ', \array_slice($tokens, 0, 2));
                    break;

                case 'RAR':
                    $yearIdx = (int) ($tokens[0] ?? 0);
                    $doc['fiscal_years'][$yearIdx] = [
                        'start' => $this->parseDate($tokens[1] ?? ''),
                        'end'   => $this->parseDate($tokens[2] ?? ''),
                    ];
                    break;

                case 'KONTO':
                    $code = $tokens[0] ?? '';
                    if ($code !== '') {
                        $doc['accounts'][$code] = [
                            'name' => $tokens[1] ?? $code,
                            'ktyp' => $doc['accounts'][$code]['ktyp'] ?? null,
                        ];
                    }
                    break;

                case 'KTYP':
                    $code = $tokens[0] ?? '';
                    if ($code !== '') {
                        if (! isset($doc['accounts'][$code])) {
                            $doc['accounts'][$code] = ['name' => $code, 'ktyp' => null];
                        }
                        $doc['accounts'][$code]['ktyp'] = strtoupper($tokens[1] ?? '');
                    }
                    break;

                case 'IB':
                    $yearIdx = (int) ($tokens[0] ?? 0);
                    $code    = $tokens[1] ?? '';
                    $amount  = $this->parseAmount($tokens[2] ?? '0');
                    if ($code !== '') {
                        $doc['opening_balances'][$yearIdx][$code] = $amount;
                    }
                    break;

                case 'UB':
                    $yearIdx = (int) ($tokens[0] ?? 0);
                    $code    = $tokens[1] ?? '';
                    $amount  = $this->parseAmount($tokens[2] ?? '0');
                    if ($code !== '') {
                        $doc['closing_balances'][$yearIdx][$code] = $amount;
                    }
                    break;

                case 'RES':
                    $yearIdx = (int) ($tokens[0] ?? 0);
                    $code    = $tokens[1] ?? '';
                    $amount  = $this->parseAmount($tokens[2] ?? '0');
                    if ($code !== '') {
                        $doc['result_amounts'][$yearIdx][$code] = $amount;
                    }
                    break;

                case 'VER':
                    $entry = $this->parseVer($tokens, $lines, $i);
                    if ($entry !== null) {
                        $doc['entries'][] = $entry;
                    }
                    break;
            }
        }

        return $doc;
    }

    // -------------------------------------------------------------------------
    // Preview
    // -------------------------------------------------------------------------

    /**
     * Return statistics about what would be imported, without touching the DB.
     */
    public function preview(array $doc, FiscalYear $fy): array
    {
        $existingCodes = Account::pluck('id', 'code');

        $newAccounts = collect($doc['accounts'])
            ->keys()
            ->filter(fn ($c) => ! $existingCodes->has($c))
            ->values();

        $ibCodes = collect($doc['opening_balances'][0] ?? [])->keys();

        $existingEntryKeys = JournalEntry::where('fiscal_year_id', $fy->id)
            ->get(['series', 'number'])
            ->map(fn ($e) => $e->series . '|' . $e->number)
            ->flip();

        $newEntries = collect($doc['entries'])
            ->filter(fn ($e) => ! $existingEntryKeys->has($e['series'] . '|' . $e['number']));

        $duplicateCount = \count($doc['entries']) - $newEntries->count();

        return [
            'org_name'          => $doc['org_name'],
            'org_number'        => $doc['org_number'],
            'program'           => $doc['program'],
            'fy_in_file'        => $doc['fiscal_years'][0] ?? null,
            'total_accounts'    => \count($doc['accounts']),
            'new_accounts'      => $newAccounts->count(),
            'new_account_codes' => $newAccounts->take(20)->all(),
            'ib_accounts'       => $ibCodes->count(),
            'total_entries'     => \count($doc['entries']),
            'new_entries'       => $newEntries->count(),
            'duplicate_entries' => $duplicateCount,
        ];
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    /**
     * Import the parsed document into a fiscal year.
     *
     * Options:
     *   create_accounts    (bool, default true)    — create accounts that don't exist in our chart of accounts
     *   import_ib          (bool, default true)    — import #IB 0 records as opening_balances
     *   import_entries     (bool, default true)    — import #VER records as journal entries + lines
     *   duplicate_handling (string, default 'skip') — 'skip' | 'overwrite'
     *     skip:      ignore entries where series+number already exists
     *     overwrite: supersede existing lines and replace with imported data (audit trail preserved)
     *
     * @return array{accounts_created: int, ib_imported: int, entries_imported: int, entries_overwritten: int, entries_skipped: int, errors: list<string>}
     */
    public function import(array $doc, FiscalYear $fy, array $options = []): array
    {
        $createAccounts    = $options['create_accounts']    ?? true;
        $importIb          = $options['import_ib']          ?? true;
        $importEntries     = $options['import_entries']      ?? true;
        $duplicateHandling = $options['duplicate_handling']  ?? 'skip';
        $userId            = Auth::id();

        return DB::transaction(function () use ($doc, $fy, $createAccounts, $importIb, $importEntries, $duplicateHandling, $userId): array {
            $result = [
                'accounts_created'  => 0,
                'ib_imported'       => 0,
                'entries_imported'  => 0,
                'entries_overwritten' => 0,
                'entries_skipped'   => 0,
                'errors'            => [],
            ];

            // --- Accounts ---
            if ($createAccounts) {
                foreach ($doc['accounts'] as $code => $info) {
                    if (Account::where('code', $code)->exists()) {
                        continue;
                    }
                    Account::create([
                        'code'      => $code,
                        'name'      => $info['name'],
                        'type'      => $this->mapKtyp($info['ktyp'], $code),
                        'is_active' => true,
                        'is_group'  => false,
                    ]);
                    $result['accounts_created']++;
                }
            }

            $codeToId = Account::pluck('id', 'code');

            // --- Opening balances ---
            if ($importIb && isset($doc['opening_balances'][0])) {
                foreach ($doc['opening_balances'][0] as $code => $amountOre) {
                    $accountId = $codeToId->get($code);
                    if (! $accountId) {
                        $result['errors'][] = "IB: konto {$code} saknas — hoppades över.";
                        continue;
                    }

                    OpeningBalance::updateOrCreate(
                        ['fiscal_year_id' => $fy->id, 'account_id' => $accountId],
                        ['amount' => $amountOre, 'created_by' => $userId, 'updated_by' => $userId],
                    );
                    $result['ib_imported']++;
                }
            }

            // --- Journal entries ---
            if ($importEntries) {
                $existingEntries = JournalEntry::where('fiscal_year_id', $fy->id)
                    ->get(['id', 'series', 'number'])
                    ->keyBy(fn ($e) => $e->series . '|' . $e->number);

                foreach ($doc['entries'] as $entryData) {
                    $key      = $entryData['series'] . '|' . $entryData['number'];
                    $existing = $existingEntries->get($key);

                    if ($existing && $duplicateHandling === 'skip') {
                        $result['entries_skipped']++;
                        continue;
                    }

                    $validLines = [];
                    foreach ($entryData['lines'] as $lineData) {
                        $accountId = $codeToId->get($lineData['code']);
                        if (! $accountId) {
                            $result['errors'][] = "Ver {$entryData['series']}{$entryData['number']}: konto {$lineData['code']} saknas — raden hoppades över.";
                            continue;
                        }
                        $validLines[] = [
                            'account_id'  => $accountId,
                            'debit'       => max(0, $lineData['amount']),
                            'credit'      => max(0, -$lineData['amount']),
                            'description' => $lineData['desc'] ?: null,
                        ];
                    }

                    if (empty($validLines)) {
                        continue;
                    }

                    if ($existing && $duplicateHandling === 'overwrite') {
                        $existing = JournalEntry::find($existing->id);
                        $existing->update([
                            'date'        => $entryData['date'],
                            'description' => $entryData['desc'],
                        ]);
                        $existing->updateLines($validLines, $userId, 'Ersatt via SIE4-import');
                        $result['entries_overwritten']++;
                        continue;
                    }

                    $entry = JournalEntry::create([
                        'fiscal_year_id' => $fy->id,
                        'series'         => $entryData['series'],
                        'number'         => $entryData['number'],
                        'date'           => $entryData['date'],
                        'description'    => $entryData['desc'],
                        'created_by'     => $userId,
                    ]);

                    foreach ($validLines as $line) {
                        $entry->lines()->create($line);
                    }

                    $result['entries_imported']++;
                }
            }

            return $result;
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function parseVer(array $tokens, array $lines, int &$i): ?array
    {
        $series = $tokens[0] ?? 'A';
        $number = (int) ($tokens[1] ?? 0);
        $date   = $this->parseDate($tokens[2] ?? '');
        $desc   = $tokens[3] ?? '';

        if ($number <= 0) {
            return null;
        }

        // Advance to opening brace
        $n = \count($lines);
        while ($i < $n && trim($lines[$i]) !== '{') {
            $i++;
        }
        $i++; // skip {

        $entry = [
            'series' => $series,
            'number' => $number,
            'date'   => $date,
            'desc'   => $desc,
            'lines'  => [],
        ];

        while ($i < $n) {
            $line = trim($lines[$i]);
            $i++;

            if ($line === '}') {
                break;
            }

            if ($line === '' || $line[0] !== '#') {
                continue;
            }

            $spacePos = strpos($line, ' ');
            $keyword  = $spacePos !== false
                ? strtoupper(substr($line, 1, $spacePos - 1))
                : strtoupper(substr($line, 1));
            $rest     = $spacePos !== false ? substr($line, $spacePos + 1) : '';

            if ($keyword !== 'TRANS') {
                continue;
            }

            $t      = $this->tokenize($rest);
            $code   = $t[0] ?? '';
            // $t[1] is the object list {}
            $amount = $this->parseAmount($t[2] ?? '0');
            // $t[3] = optional date override, $t[4] = optional description
            $tdesc  = $t[4] ?? '';

            if ($code !== '') {
                $entry['lines'][] = [
                    'code'   => $code,
                    'amount' => $amount,
                    'desc'   => $tdesc,
                ];
            }
        }

        return $entry;
    }

    /**
     * Tokenize a SIE4 record argument string into tokens.
     * Handles: bare tokens, "quoted strings" (with \" and \\ escapes), {object lists}.
     */
    private function tokenize(string $line): array
    {
        $tokens = [];
        $i      = 0;
        $len    = \strlen($line);

        while ($i < $len) {
            if (ctype_space($line[$i])) {
                $i++;
                continue;
            }

            if ($line[$i] === '"') {
                $i++;
                $val = '';
                while ($i < $len && $line[$i] !== '"') {
                    if ($line[$i] === '\\' && $i + 1 < $len) {
                        $val .= $line[++$i];
                    } else {
                        $val .= $line[$i];
                    }
                    $i++;
                }
                $i++; // closing "
                $tokens[] = $val;
            } elseif ($line[$i] === '{') {
                $depth = 1;
                $val   = '{';
                $i++;
                while ($i < $len && $depth > 0) {
                    if ($line[$i] === '{') {
                        $depth++;
                    }
                    if ($line[$i] === '}') {
                        $depth--;
                    }
                    $val .= $line[$i++];
                }
                $tokens[] = $val;
            } else {
                $start = $i;
                while ($i < $len && ! ctype_space($line[$i])) {
                    $i++;
                }
                $tokens[] = substr($line, $start, $i - $start);
            }
        }

        return $tokens;
    }

    /** Parse SIE4 date Ymd → Y-m-d, or return as-is if already formatted. */
    private function parseDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return $date;
    }

    /** Parse SIE4 amount (decimal kronor, period as decimal) → integer öre. */
    private function parseAmount(string $s): int
    {
        return (int) round((float) str_replace(',', '.', trim($s)) * 100);
    }

    /**
     * Map SIE4 KTYP to our account type.
     * S (skuld/eget kapital): 20xx → equity, rest → liability.
     * When ktyp is null (Fortnox omits #KTYP), infer from BAS account code range.
     */
    private function mapKtyp(?string $ktyp, string $code): string
    {
        if ($ktyp !== null) {
            return match ($ktyp) {
                'T'     => Account::TYPE_ASSET,
                'I'     => Account::TYPE_INCOME,
                'K'     => Account::TYPE_EXPENSE,
                'S'     => str_starts_with($code, '20') ? Account::TYPE_EQUITY : Account::TYPE_LIABILITY,
                default => $this->inferTypeFromCode($code),
            };
        }

        return $this->inferTypeFromCode($code);
    }

    /**
     * Infer account type from BAS kontoplan code range.
     * 1xxx = asset, 20xx = equity, 2xxx = liability, 3xxx = income, 4-8xxx = expense.
     */
    private function inferTypeFromCode(string $code): string
    {
        return match (true) {
            str_starts_with($code, '1')  => Account::TYPE_ASSET,
            str_starts_with($code, '20') => Account::TYPE_EQUITY,
            str_starts_with($code, '2')  => Account::TYPE_LIABILITY,
            str_starts_with($code, '3')  => Account::TYPE_INCOME,
            default                      => Account::TYPE_EXPENSE,
        };
    }
}
