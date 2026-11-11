<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_dimensions', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('label'); // "Storlek", "Färg", "Format"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_dimensions');
    }
};
