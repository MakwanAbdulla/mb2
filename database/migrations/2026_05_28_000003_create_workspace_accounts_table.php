<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('accountable_type')->nullable();
            $table->unsignedBigInteger('accountable_id')->nullable();
            $table->index(['accountable_type', 'accountable_id']);
            $table->timestamp('pending_deletion_at')->nullable();
            $table->timestamps();
        });

        // Migrate personal accounts from members.google_workspace_email
        DB::statement("
            INSERT INTO workspace_accounts (email, accountable_type, accountable_id, pending_deletion_at, created_at, updated_at)
            SELECT google_workspace_email, 'member', id, workspace_pending_deletion_at, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM members
            WHERE google_workspace_email IS NOT NULL
              AND deleted_at IS NULL
        ");

        // Migrate shared/functional accounts from workspace_shared_accounts
        // Use subquery to skip emails already inserted (personal accounts)
        $driver = DB::getDriverName();
        $ignore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        DB::statement("
            {$ignore} INTO workspace_accounts (email, display_name, description, accountable_type, accountable_id, pending_deletion_at, created_at, updated_at)
            SELECT
                email,
                display_name,
                description,
                CASE
                    WHEN organizational_role_id IS NOT NULL THEN 'org_role'
                    WHEN section_id IS NOT NULL            THEN 'section'
                    ELSE NULL
                END,
                CASE
                    WHEN organizational_role_id IS NOT NULL THEN organizational_role_id
                    WHEN section_id IS NOT NULL            THEN section_id
                    ELSE NULL
                END,
                pending_deletion_at,
                created_at,
                updated_at
            FROM workspace_shared_accounts
        ");

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['google_workspace_email', 'workspace_pending_deletion_at']);
        });

        Schema::drop('workspace_shared_accounts');
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('google_workspace_email')->nullable()->after('cogworks_id');
            $table->timestamp('workspace_pending_deletion_at')->nullable()->after('google_workspace_email');
        });

        Schema::create('workspace_shared_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organizational_role_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('pending_deletion_at')->nullable();
            $table->timestamps();
        });

        // Restore personal accounts (MySQL join-update; SQLite uses subquery form)
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE members SET
                    google_workspace_email = (SELECT email FROM workspace_accounts WHERE accountable_type = 'member' AND accountable_id = members.id LIMIT 1),
                    workspace_pending_deletion_at = (SELECT pending_deletion_at FROM workspace_accounts WHERE accountable_type = 'member' AND accountable_id = members.id LIMIT 1)
                WHERE EXISTS (SELECT 1 FROM workspace_accounts WHERE accountable_type = 'member' AND accountable_id = members.id)
            ");
        } else {
            DB::statement("
                UPDATE members m
                JOIN workspace_accounts wa ON wa.accountable_type = 'member' AND wa.accountable_id = m.id
                SET m.google_workspace_email = wa.email,
                    m.workspace_pending_deletion_at = wa.pending_deletion_at
            ");
        }

        // Restore shared accounts
        DB::statement("
            INSERT INTO workspace_shared_accounts (email, display_name, description, section_id, organizational_role_id, pending_deletion_at, created_at, updated_at)
            SELECT
                email,
                COALESCE(display_name, email),
                description,
                CASE WHEN accountable_type = 'section'   THEN accountable_id ELSE NULL END,
                CASE WHEN accountable_type = 'org_role'  THEN accountable_id ELSE NULL END,
                pending_deletion_at,
                created_at,
                updated_at
            FROM workspace_accounts
            WHERE accountable_type != 'member' OR accountable_type IS NULL
        ");

        Schema::drop('workspace_accounts');
    }
};
