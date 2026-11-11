<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('event_addons')->cascadeOnDelete();
            $table->string('option_value')->nullable();     // vald variant, t.ex. "M"
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->string('item_token', 32)->nullable()->unique();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['registration_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_addons');
    }
};
