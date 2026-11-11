<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\WorkspaceAccount;
use App\Services\GoogleWorkspaceService;
use Illuminate\Console\Command;

class WorkspacePurgePendingDeletions extends Command
{
    protected $signature   = 'workspace:purge-pending-deletions';
    protected $description = 'Permanently delete Workspace accounts that have been suspended for 5 days.';

    public function handle(GoogleWorkspaceService $ws): int
    {
        $deleted = 0;

        WorkspaceAccount::whereNotNull('pending_deletion_at')
            ->where('pending_deletion_at', '<=', now())
            ->each(function (WorkspaceAccount $account) use ($ws, &$deleted) {
                $ok = $ws->deleteAccount($account->email);

                if ($ok) {
                    AuditLog::record('workspace_account_purged', 'workspace', $account->email, null);
                    $account->delete();
                    $this->info("Deleted: {$account->email}");
                    $deleted++;
                } else {
                    $this->error("Failed to delete: {$account->email}");
                }
            });

        if ($deleted === 0) {
            $this->info('No accounts due for deletion.');
        }

        return self::SUCCESS;
    }
}
