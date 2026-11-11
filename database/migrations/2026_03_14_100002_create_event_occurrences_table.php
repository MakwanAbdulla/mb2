<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();           // "Lektion 3", "Dag 2" etc.
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('location_override')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('min_participants')->nullable();
            $table->boolean('is_dropin')->default(false);
            $table->datetime('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
