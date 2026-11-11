<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->string('type')->default('physical');
            $table->string('status')->default('draft');
            $table->unsignedInteger('price_ore')->default(0);
            $table->unsignedInteger('member_price_ore')->nullable();
            $table->boolean('requires_membership')->default(false);
            $table->boolean('show_in_event_checkout')->default(false);
            $table->boolean('pickup_only')->default(false);
            $table->boolean('track_stock')->default(false);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedSmallInteger('length_mm')->nullable();
            $table->unsignedSmallInteger('width_mm')->nullable();
            $table->unsignedSmallInteger('height_mm')->nullable();
            $table->string('digital_file_path')->nullable();
            $table->string('image_path')->nullable();
            $table->tinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
