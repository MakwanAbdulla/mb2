<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wiki_page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wiki_page_id', 'id']);
        });

        // Pages written before revisions existed get one, so every page has a history.
        DB::table('wiki_pages')->orderBy('id')->each(fn ($page) => DB::table('wiki_page_revisions')->insert([
            'wiki_page_id' => $page->id,
            'title' => $page->title,
            'content' => $page->content,
            'user_id' => $page->updated_by,
            'summary' => null,
            'created_at' => $page->updated_at ?? now(),
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_revisions');
    }
};
