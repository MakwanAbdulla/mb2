<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->foreignId('price_tier_id')->nullable()->constrained('event_price_tiers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending_payment'); // pending_payment|confirmed|waitlisted|cancelled|transferred
            $table->string('check_in_token')->unique();
            $table->foreignId('transferred_from_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->foreignId('transfer_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('cancelled_at')->nullable();
            $table->unsignedBigInteger('form_submission_id')->nullable()
                ->comment('FK to form_submissions — no constraint here, forms table created later');
            $table->text('notes')->nullable();
            $table->boolean('is_walkin')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
