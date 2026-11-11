<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('ocr_number')->nullable()->unique();
            $table->string('invoice_number', 30)->nullable()->unique();
            $table->timestamp('invoice_issued_at')->nullable();
            $table->string('invoice_brand', 20)->default('armagedon');
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_ore')->default(0);
            $table->unsignedInteger('discount_ore')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('swish_payment_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('membership');
            $table->string('slot_role')->nullable();
            $table->nullableMorphs('orderable');
            $table->string('description');
            $table->unsignedInteger('unit_price_ore');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('discount_ore')->default(0);
            $table->unsignedInteger('line_total_ore');
            $table->timestamps();
        });

        // FK from membership_periods to orders (orders must exist first)
        Schema::table('membership_periods', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membership_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
