<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categories of rentable things: "Stort skåp", "Litet skåp", "Cykelplats", etc.
        Schema::create('rental_item_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // e.g. "Stort skåp"
            $table->text('description')->nullable();
            $table->unsignedInteger('price_per_year');    // öre
            $table->unsignedInteger('setup_fee_ore')->default(0)
                ->comment('One-time acquisition fee in öre. 0 = no fee.');
            $table->boolean('is_active')->default(true)
                ->comment('Inactive types are hidden from members (no new waitlist signups)');
            $table->boolean('is_admin_controlled')->default(false);
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();
        });

        // Individual rentable units: locker #1, locker #56, bike spot "A3", etc.
        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_type_id')->constrained()->cascadeOnDelete();
            $table->string('label')
                ->comment('Human-readable identifier: "1", "35", "A3", etc.');
            $table->boolean('is_available')->default(true)
                ->comment('False = broken/decommissioned, cannot be rented');
            $table->text('notes')->nullable();
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();

            $table->unique(['rental_item_type_id', 'label']);
        });

        // Active and historical rentals
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_per_year')
                ->comment('Snapshot of price at time of rental, in öre');
            $table->date('started_at');
            $table->date('ended_at')->nullable()
                ->comment('null = currently active rental');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['rental_item_id', 'ended_at']);
            $table->index(['member_id', 'ended_at']);
        });

        // Waiting list — one entry per member per item type
        Schema::create('rental_waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps(); // created_at determines queue position

            $table->unique(['rental_item_type_id', 'member_id']);
        });

        Schema::create('rental_type_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('price_ore');
            $table->timestamps();
            $table->unique(['rental_item_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_type_prices');
        Schema::dropIfExists('rental_waitlists');
        Schema::dropIfExists('rentals');
        Schema::dropIfExists('rental_items');
        Schema::dropIfExists('rental_item_types');
    }
};
