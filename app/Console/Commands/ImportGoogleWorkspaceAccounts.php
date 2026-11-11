<?php

namespace App\Console\Commands;

use App\Models\Membership\Member;
use App\Models\WorkspaceAccount;
use App\Services\GoogleWorkspaceService;
use Illuminate\Console\Command;

class ImportGoogleWorkspaceAccounts extends Command
{
    protected $signature = 'workspace:import
                            {--dry-run : Visa matchningar utan att spara}';

    protected $description = 'Matcha befintliga @armagedon.se-konton mot medlemmar och skapa WorkspaceAccount-poster';

    public function handle(GoogleWorkspaceService $workspace): int
    {
        if (! $workspace->isConfigured()) {
            $this->error('Google Workspace är inte konfigurerat.');
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar sparas.');
        }

        $this->info('Hämtar konton från Google Workspace…');
        $wsUsers = $workspace->listUsers();

        if ($wsUsers === null) {
            $this->error('Kunde inte hämta användare från Google Workspace.');
            return self::FAILURE;
        }

        $this->info(count($wsUsers) . ' konton hittades.');

        // Index members by full name (lowercase) and by email for fast lookup
        $membersByEmail = Member::whereNotNull('email')
            ->get()
            ->keyBy(fn ($m) => strtolower($m->email));

        $membersByName = Member::all()
            ->keyBy(fn ($m) => $this->normalize(trim($m->first_name . ' ' . $m->last_name)));

        $matched   = 0;
        $skipped   = 0;
        $ambiguous = 0;

        foreach ($wsUsers as $ws) {
            $email    = $ws['email'];
            $fullName = $this->normalize(trim($ws['full_name']));

            // Skip if already linked to a member
            if (WorkspaceAccount::where('email', $email)->exists()) {
                $this->line("  <fg=yellow>Redan länkad:</> {$email}");
                $skipped++;
                continue;
            }

            // Try to match by personal email first, then by normalized name
            $member = $membersByEmail->get(strtolower($email))
                ?? $membersByName->get($fullName);

            if (! $member) {
                $this->line("  <fg=red>Ingen träff:</> {$ws['full_name']} ({$email})");
                $ambiguous++;
                continue;
            }

            $status = $ws['suspended'] ? ' <fg=yellow>[avstängd]</>' : '';
            $this->line("  <fg=green>Matchad:</> {$ws['full_name']} ({$email}) → #{$member->membership_number} {$member->full_name}{$status}");

            if (! $dryRun) {
                $member->workspaceAccount()->updateOrCreate([], ['email' => $email]);
            }

            $matched++;
        }

        $this->newLine();
        $this->info("Klart. Matchade: {$matched}, redan länkade: {$skipped}, ingen träff: {$ambiguous}.");

        if ($ambiguous > 0) {
            $this->line('Konton utan träff kan länkas manuellt via admin-gränssnittet.');
        }

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar sparades.');
        }

        return self::SUCCESS;
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower($name);
        return strtr($name, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u', 'ñ' => 'n']);
    }
}
