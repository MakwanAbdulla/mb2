<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')
                ->comment('draft|active|closed');
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_open')->default(false)
                ->comment('Anyone with the link can respond, no invitation required');
            $table->boolean('notify_on_response')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('opens_at')->nullable()
                ->comment('Auto-activate at this time');
            $table->timestamp('closes_at')->nullable()
                ->comment('Auto-close at this time');
            $table->timestamp('send_invitations_at')->nullable()
                ->comment('Send pending invitations at this time');
            $table->nullableMorphs('surveyable');
            $table->string('role')->nullable()
                ->comment('registration|followup|null=standalone');
            $table->unsignedBigInteger('form_id')->nullable()
                ->comment('FK to forms — no constraint here, forms table created later');
            $table->timestamps();
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('surveys');
    }
};
