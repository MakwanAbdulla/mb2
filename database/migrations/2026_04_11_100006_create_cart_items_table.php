<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            // ProductVariant | EventPriceTier
            $table->morphs('cartable');
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_ore'); // price snapshot at time of adding
            // For EventPriceTier items:
            // { event_id, occurrence_ids[], addon_selections[], attendee_name, attendee_email }
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
