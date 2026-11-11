<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Clean up storage directories that would become orphaned after migrate:fresh
        $this->cleanStorage();

        $this->call([
            PermissionSeeder::class,
            OrganizationalRoleSeeder::class,
            PageSeeder::class,
            HelpTextSeeder::class,
            MembershipFeeSeeder::class,
            MembershipSettingsSeeder::class,
            MembershipPaymentSeeder::class,
            MailTemplateSeeder::class,
            AccountSeeder::class,
            PaymentProviderSeeder::class,
            VendorSeeder::class,
            PaymentTermSeeder::class,
            JournalSeriesSeeder::class,
            CostCenterSeeder::class,
            AccountingInboxSenderSeeder::class,
            PostingRuleSeeder::class,
            RentalItemSeeder::class,
            BookingSettingsSeeder::class,
            BookingDataSeeder::class,
            MeetingTemplateSeeder::class,
        ]);

        $this->command->info('Kör CogWorks-import…');
        $cogworksArgs = [];
        $cacheFile = base_path('database/fixtures/cogworks.json');
        if (file_exists($cacheFile)) {
            $this->command->info('  → Cache hittad, använder database/fixtures/cogworks.json');
            $cogworksArgs['--from-cache'] = true;
            $cogworksArgs['--skip-avatars'] = true;
        }
        Artisan::call('import:cogworks', $cogworksArgs, $this->command->getOutput());

        // TestUserSeeder runs after import so Örjan's CogWorks user already exists
        $this->call(TestUserSeeder::class);
        $this->call(FamilyAccountSeeder::class);
        $this->call(CorporateCardSeeder::class);

        // Populate google_workspace_email on members so InboxMailboxSeeder can create user mailboxes.
        // Skipped silently when GWS isn't configured (CI, staging without credentials).
        if (app(\App\Services\GoogleWorkspaceService::class)->isConfigured()) {
            $this->command->info('Kör workspace:import…');
            Artisan::call('workspace:import', [], $this->command->getOutput());
        }

        // InboxMailboxSeeder must run after org roles + CogWorks import + workspace:import
        $this->call(InboxMailboxSeeder::class);
        $this->call(DevDataSeeder::class);
    }

    private function cleanStorage(): void
    {
        $dirs = [
            'accounting/inbox',
            'accounting/documents',
            'expense-claims',
        ];

        foreach ($dirs as $dir) {
            if (Storage::directoryExists($dir)) {
                Storage::deleteDirectory($dir);
                $this->command->info("Rensade storage: {$dir}");
            }

            // Recreate with group www-data so the web server can create subdirectories.
            // Seeders run as the CLI user (orjan); without this fix the created dirs
            // end up as orjan:orjan and www-data cannot mkdir inside them.
            $abs = Storage::path($dir);
            if (!is_dir($abs)) {
                mkdir($abs, 0775, true);
            }
            @chgrp($abs, 'www-data');
            $this->command->info("Skapade storage: {$dir}");
        }
    }
}
