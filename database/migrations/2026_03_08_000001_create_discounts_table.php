<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed']);
            $table->unsignedInteger('value');
            $table->enum('applies_to', ['membership', 'event', 'shop', 'all'])->default('all');
            $table->string('membership_type')->nullable();
            $table->boolean('requires_membership')->nullable();
            $table->string('condition')->nullable();
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('max_discount')->nullable();
            $table->unsignedTinyInteger('max_pct_of_total')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('logo', ['armagedon', 'bsk', 'none'])->default('armagedon');
            $table->nullableMorphs('applicable');
            $table->timestamps();
        });

        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->boolean('is_single_use')->default(true);
            $table->unsignedSmallInteger('max_uses')->nullable();
            $table->string('batch_label')->nullable();
            $table->foreignId('redeemed_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('discountable');
            $table->unsignedInteger('original_amount');
            $table->unsignedInteger('discount_amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_uses');
        Schema::dropIfExists('discount_codes');
        Schema::dropIfExists('discounts');
    }
};
