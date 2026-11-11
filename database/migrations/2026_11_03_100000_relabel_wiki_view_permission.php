<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // PermissionSeeder only sets a label when it is empty, so an installation
    // seeded with the old "Se wikin" keeps it unless it is changed here.
    public function up(): void
    {
        DB::table('permissions')->where('name', 'wiki.view')->update(['label' => 'Se alla wikiutrymmen']);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'wiki.view')->update(['label' => 'Se wikin']);
    }
};
