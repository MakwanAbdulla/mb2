<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Utrymmen ────────────────────────────────────────────────────────────
        // Owned by a group, or by nobody (association-wide). One space per group;
        // any number of association-wide spaces (NULLs don't collide).
        // group_id points at `sections`, which #168 renames to `groups` keeping
        // its ids, so the column survives the rename unchanged. Restrict on
        // delete: removing a group must not silently drop its knowledge.
        Schema::create('wiki_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->unique()->constrained('sections')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('read_access', 20)->default('members');
            $table->timestamps();
        });

        // ── Sidor ───────────────────────────────────────────────────────────────
        // A tree per space via parent_id. Soft-deleted pages keep their slug so
        // they can be restored without a collision.
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wiki_space_id')->constrained('wiki_spaces')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['wiki_space_id', 'slug']);
            $table->index(['wiki_space_id', 'parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
        Schema::dropIfExists('wiki_spaces');
    }
};
