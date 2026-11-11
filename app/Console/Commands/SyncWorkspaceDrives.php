<?php

namespace App\Console\Commands;

use App\Services\GoogleWorkspaceService;
use Illuminate\Console\Command;

class SyncWorkspaceDrives extends Command
{
    protected $signature   = 'workspace:sync-drives';
    protected $description = 'Sync Google Shared Drive membership based on section/role assignments';

    public function handle(GoogleWorkspaceService $ws): int
    {
        if (! $ws->isConfigured()) {
            $this->error('Google Workspace is not configured.');
            return self::FAILURE;
        }

        $count = $ws->syncDriveMembers();
        $this->info("Synced {$count} drive member(s).");
        return self::SUCCESS;
    }
}
