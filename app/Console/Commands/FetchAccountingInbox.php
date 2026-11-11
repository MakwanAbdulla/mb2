<?php

namespace App\Console\Commands;

use App\Services\Accounting\InboxMailFetcher;
use Illuminate\Console\Command;

class FetchAccountingInbox extends Command
{
    protected $signature   = 'accounting:fetch-inbox';
    protected $description = 'Fetch unseen emails from the accounting inbox and import attachments';

    public function handle(): int
    {
        if (! InboxMailFetcher::isConfigured()) {
            $this->warn('Accounting inbox is not configured (ACCOUNTING_INBOX_HOST missing).');
            return self::FAILURE;
        }

        $this->info('Connecting to inbox…');

        try {
            $result = (new InboxMailFetcher())->fetch();
        } catch (\Throwable $e) {
            $this->error('Failed to fetch inbox: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. Messages: %d | Imported: %d | Needs approval: %d | Duplicates skipped: %d | Sender blocked: %d',
            $result['messages'],
            $result['imported'],
            $result['needs_approval'],
            $result['skipped_duplicate'],
            $result['skipped_blocked'],
        ));

        return self::SUCCESS;
    }
}
