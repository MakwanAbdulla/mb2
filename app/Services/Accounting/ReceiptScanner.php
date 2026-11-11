<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scans a receipt image or PDF using Claude vision and suggests a BAS account.
 *
 * Accepts: JPEG, PNG, GIF, WEBP (base64 vision), PDF (pdftotext + text API).
 * Returns structured data to pre-fill a journal entry creation form.
 */
class ReceiptScanner
{
    private const ANTHROPIC_URL     = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const PDFTOTEXT         = '/usr/bin/pdftotext';

    private const IMAGE_MIMES = [
        'image/jpeg' => 'image/jpeg',
        'image/png'  => 'image/png',
        'image/gif'  => 'image/gif',
        'image/webp' => 'image/webp',
    ];

    /**
     * Scan a receipt file and return extracted + suggested data.
     *
     * @param  string $filePath  Absolute path to the uploaded file
     * @param  string $mimeType  MIME type of the file
     * @return array{
     *   date: string|null,
     *   vendor: string|null,
     *   amount_ore: int|null,
     *   description: string|null,
     *   suggested_account_code: string|null,
     *   suggested_account_name: string|null,
     *   suggested_account_id: int|null,
     *   confidence: string|null,
     *   raw_text: string|null,
     *   error: string|null,
     * }
     */
    public function scan(string $filePath, string $mimeType): array
    {
        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            return $this->error('Anthropic API-nyckel saknas. Kontrollera ANTHROPIC_API_KEY i .env.');
        }

        // Build the message content depending on file type
        try {
            if (isset(self::IMAGE_MIMES[$mimeType])) {
                $content  = $this->buildImageContent($filePath, $mimeType);
                $rawText  = null;
            } elseif ($mimeType === 'application/pdf') {
                [$content, $rawText] = $this->buildPdfContent($filePath);
            } else {
                return $this->error("Filtypen {$mimeType} stöds inte. Använd JPEG, PNG eller PDF.");
            }
        } catch (\Throwable $e) {
            return $this->error('Kunde inte läsa filen: ' . $e->getMessage());
        }

        $accountList = $this->buildAccountList();
        $systemPrompt = $this->buildSystemPrompt($accountList);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ])
                ->post(self::ANTHROPIC_URL, [
                    'model'      => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 512,
                    'system'     => $systemPrompt,
                    'messages'   => [['role' => 'user', 'content' => $content]],
                ]);

            if (! $response->successful()) {
                Log::warning('ReceiptScanner: Anthropic error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $this->error('API-anropet misslyckades (HTTP ' . $response->status() . ').');
            }

            $raw = $response->json('content.0.text', '');
            $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw = preg_replace('/\s*```$/', '', $raw);

            $data = json_decode($raw, true);

            if (! is_array($data)) {
                Log::warning('ReceiptScanner: could not parse response', compact('raw'));
                return $this->error('Kunde inte tolka svaret från Claude.');
            }

            return $this->normalise($data, $rawText);
        } catch (\Throwable $e) {
            Log::warning('ReceiptScanner: exception', ['error' => $e->getMessage()]);
            return $this->error('Oväntat fel: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------

    private function buildImageContent(string $filePath, string $mimeType): array
    {
        $data = base64_encode(\file_get_contents($filePath));

        return [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => self::IMAGE_MIMES[$mimeType],
                    'data'       => $data,
                ],
            ],
            [
                'type' => 'text',
                'text' => 'Analysera detta kvitto och returnera JSON enligt systemprompten.',
            ],
        ];
    }

    /** @return array{0: array, 1: string|null} */
    private function buildPdfContent(string $filePath): array
    {
        $text = null;

        if (\file_exists(self::PDFTOTEXT)) {
            $out = shell_exec(self::PDFTOTEXT . ' -layout ' . escapeshellarg($filePath) . ' -');
            if ($out !== null && $out !== '') {
                $text = mb_substr($out, 0, 6000);
            }
        }

        if ($text === null) {
            throw new \RuntimeException('pdftotext saknas eller kunde inte läsa PDF-filen.');
        }

        return [
            [['type' => 'text', 'text' => "Kvittotext (extraherad från PDF):\n\n{$text}"]],
            $text,
        ];
    }

    private function buildAccountList(): string
    {
        $accounts = Account::active()->leaf()
            ->whereIn('type', [
                Account::TYPE_EXPENSE,
                Account::TYPE_ASSET,
                Account::TYPE_LIABILITY,
            ])
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => "{$a->code} {$a->name}")
            ->implode("\n");

        return $accounts ?: '(Inga konton tillgängliga)';
    }

    private function buildSystemPrompt(string $accountList): string
    {
        return <<<PROMPT
Du är en bokföringsassistent för en svensk förening som använder BAS-kontoplanen.

Analysera kvittot och returnera ENBART ett JSON-objekt, ingen annan text:

{
  "date":                   "YYYY-MM-DD" eller null,
  "vendor":                 leverantörens namn (sträng) eller null,
  "amount_ore":             totalt belopp inkl. moms i heltal öre (1 kr = 100 öre) eller null,
  "description":            kort beskrivning för verifikationens textfält (max 80 tecken),
  "suggested_account_code": bäst matchande kontonummer från listan nedan (sträng, t.ex. "5410"),
  "confidence":             "high" | "medium" | "low" — hur säker du är på kontovalet,
  "reasoning":              en mening om varför du valde kontot
}

Regler:
- Välj konto utifrån vad köpet handlar om, inte leverantörens namn
- Välj alltid ett konto från listan nedan — välj det som passar bäst
- Belopp: svenska format "1 234,56 kr" → 123456 öre; engelska "1,234.56" → 123456 öre
- Om beloppet är oklart, sätt amount_ore till null

Tillgängliga konton:
{$accountList}
PROMPT;
    }

    private function normalise(array $data, ?string $rawText): array
    {
        $code    = isset($data['suggested_account_code']) ? (string) $data['suggested_account_code'] : null;
        $account = $code ? Account::where('code', $code)->first() : null;

        $amountOre = null;
        if (isset($data['amount_ore']) && is_numeric($data['amount_ore'])) {
            $amountOre = (int) $data['amount_ore'];
        }

        return [
            'date'                   => isset($data['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['date'])
                ? $data['date']
                : null,
            'vendor'                 => isset($data['vendor']) && is_string($data['vendor']) ? $data['vendor'] : null,
            'amount_ore'             => $amountOre,
            'description'            => isset($data['description']) && is_string($data['description'])
                ? mb_substr($data['description'], 0, 80)
                : null,
            'suggested_account_code' => $account?->code,
            'suggested_account_name' => $account?->name,
            'suggested_account_id'   => $account?->id,
            'confidence'             => in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $data['confidence']
                : null,
            'reasoning'              => isset($data['reasoning']) && is_string($data['reasoning'])
                ? $data['reasoning']
                : null,
            'raw_text'               => $rawText,
            'error'                  => null,
        ];
    }

    private function error(string $message): array
    {
        return [
            'date'                   => null,
            'vendor'                 => null,
            'amount_ore'             => null,
            'description'            => null,
            'suggested_account_code' => null,
            'suggested_account_name' => null,
            'suggested_account_id'   => null,
            'confidence'             => null,
            'reasoning'              => null,
            'raw_text'               => null,
            'error'                  => $message,
        ];
    }
}
