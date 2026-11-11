<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

// One-time backfill: groups created before the wiki get their space. New groups
// get one from SectionObserver. The command is idempotent and can repair later.
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('wiki:create-group-spaces');
    }

    public function down(): void
    {
        // Spaces stay: they may hold pages by now.
    }
};
