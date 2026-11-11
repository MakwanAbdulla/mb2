<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('xp_value');
            $table->string('type'); // admin_assign | claimable | peer
            $table->boolean('requires_approval')->default(false);
            $table->unsignedSmallInteger('daily_completions_per_member')->default(1); // max times a member can complete/receive per day
            $table->unsignedSmallInteger('daily_gives_per_member')->default(3);       // max times a member can give (peer type) per day
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('gamification_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('gamification_tasks')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();           // recipient
            $table->foreignId('given_by_member_id')->nullable()->constrained('members')->nullOnDelete(); // peer/self-report giver
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('xp_log_id')->nullable(); // set when XP is awarded
            $table->string('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_task_completions');
        Schema::dropIfExists('gamification_tasks');
    }
};
