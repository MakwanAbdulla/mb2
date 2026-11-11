<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->nullableMorphs('sourceable');
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Null = styrelse/föreningen');
            $table->string('status', 30)->default('open')
                ->comment('open|in_progress|done|cancelled');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->string('source_gmail_id')->nullable()->index();
            $table->string('source_gmail_subject')->nullable();
            $table->string('source_gmail_from', 320)->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['section_id', 'status']);
        });

        Schema::create('meeting_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('meeting_tasks')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_task_assignees');
        Schema::dropIfExists('meeting_tasks');
    }
};
