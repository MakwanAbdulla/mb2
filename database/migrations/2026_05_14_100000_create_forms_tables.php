<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('form_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('type')
                ->comment('text|textarea|radio|checkbox|scale|datetime');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->integer('scale_min')->nullable();
            $table->integer('scale_max')->nullable();
            $table->string('scale_icon')->nullable()
                ->comment('star|heart|thumb|number');
            $table->string('datetime_variant')->nullable()
                ->comment('date|time|datetime');
            $table->decimal('number_min', 12, 4)->nullable();
            $table->decimal('number_max', 12, 4)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('event_ids')->nullable();
            $table->json('section_ids')->nullable();
            $table->boolean('section_multi')->default(false);
            $table->string('membership_type')->nullable();
            $table->foreignId('rental_item_type_id')->nullable()->constrained('rental_item_types')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('form_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('form_questions')->cascadeOnDelete();
            $table->string('text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->nullableMorphs('submittable'); // Survey, EventRegistration, …
            $table->foreignId('invitation_id')->nullable()->constrained('survey_invitations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('form_submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('form_questions')->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('form_question_options')->nullOnDelete();
            $table->text('answer_text')->nullable();
            $table->timestamps();
        });

        // ── Deferred FK constraints (tables existed before forms) ─────────────

        Schema::table('surveys', function (Blueprint $table) {
            $table->foreign('form_id')->references('id')->on('forms')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreign('registration_form_id')->references('id')->on('forms')->nullOnDelete();
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->foreign('form_submission_id')->references('id')->on('form_submissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['form_submission_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['registration_form_id']);
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->dropForeign(['form_id']);
        });

        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_question_options');
        Schema::dropIfExists('form_questions');
        Schema::dropIfExists('forms');
    }
};
