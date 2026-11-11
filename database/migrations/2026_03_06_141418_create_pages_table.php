<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('seeder_key')->nullable()->unique();
            $table->string('path_prefix', 100)->nullable();
            $table->string('title');
            $table->string('title_draft')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('subtitle_draft')->nullable();
            $table->string('slug')->unique();
            $table->longText('content');
            $table->longText('content_draft')->nullable();
            $table->boolean('has_draft')->default(false);
            $table->string('image_path')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('show_in_nav')->default(true);
            $table->string('nav_title')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index('is_published');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
