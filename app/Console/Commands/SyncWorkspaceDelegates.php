<?php

namespace App\Console\Commands;

use App\Services\GoogleWorkspaceService;
use Illuminate\Console\Command;

class SyncWorkspaceDelegates extends Command
{
    protected $signature   = 'workspace:sync-delegates';
    protected $description = 'Sync Gmail delegates from Google API into workspace_delegates cache table';

    public function handle(GoogleWorkspaceService $ws): int
    {
        if (! $ws->isConfigured()) {
            $this->error('Google Workspace is not configured.');
            return self::FAILURE;
        }

        $count = $ws->syncDelegates();
        $this->info("Synced {$count} delegate(s).");
        return self::SUCCESS;
    }
}
