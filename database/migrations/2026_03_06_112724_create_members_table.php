<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('google_workspace_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('membership_type')->default('single');
            $table->string('status')->default('pending');
            $table->string('cogworks_id')->nullable()->unique();
            $table->string('membership_number')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('membership_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            $table->string('membership_type');
            $table->string('status')->default('pending_payment');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'year']);
        });

        Schema::create('membership_fees', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->string('membership_type');
            $table->unsignedInteger('amount');
            $table->tinyInteger('min_age')->unsigned()->nullable();
            $table->tinyInteger('max_age')->unsigned()->nullable();
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['year', 'membership_type']);
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->foreignId('family_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('adult');
            $table->boolean('has_own_account')->default(false);
            $table->string('status')->default('active');
            $table->string('invitation_token')->nullable()->unique();
            $table->timestamp('invited_at')->nullable();

            $table->primary(['family_group_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('membership_fees');
        Schema::dropIfExists('membership_periods');
        Schema::dropIfExists('members');
    }
};
