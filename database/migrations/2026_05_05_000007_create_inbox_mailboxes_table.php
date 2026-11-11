<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 'office' | 'accounting' | null (custom)
            $table->string('purpose')->nullable()->index();
            // 'google_workspace' | 'imap'
            $table->string('driver')->default('google_workspace');
            // Google Workspace: the mailbox address to impersonate via DWD
            $table->string('mailbox')->nullable();
            // IMAP
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl');
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();   // Laravel encrypted
            $table->string('imap_folder')->default('INBOX');
            // SMTP for replies (imap driver only)
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();   // Laravel encrypted
            $table->boolean('is_enabled')->default(true);
            $table->nullableMorphs('owner');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_mailboxes');
    }
};
