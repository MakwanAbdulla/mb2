<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_member_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->nullOnDelete()->constrained('members');
            // joined: self-registration / initial setup
            // added:  added by family admin
            // left:   voluntarily left
            // removed: removed by family admin
            $table->string('action');
            $table->foreignId('performed_by_user_id')->nullable()->nullOnDelete()->constrained('users');
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignId('membership_period_id')->nullable()->nullOnDelete()->constrained('membership_periods');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_member_history');
    }
};
