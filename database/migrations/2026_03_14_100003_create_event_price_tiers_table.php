<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_ore')->default(0);
            $table->boolean('requires_membership')->default(false);
            $table->foreignId('access_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->unsignedInteger('max_count')->nullable();
            $table->timestamps();
        });

        // Pivot: which occurrences does this tier cover?
        // Empty = all occurrences. With rows = only those occurrences.
        Schema::create('event_price_tier_occurrences', function (Blueprint $table) {
            $table->foreignId('price_tier_id')->constrained('event_price_tiers')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->primary(['price_tier_id', 'occurrence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_price_tier_occurrences');
        Schema::dropIfExists('event_price_tiers');
    }
};
