<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#6366f1');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('booking_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->integer('capacity')->default(1);
            $table->integer('max_duration')->nullable(); // minutes
            $table->integer('min_duration')->default(30); // minutes
            $table->boolean('requires_approval')->default(false);
            $table->boolean('active')->default(true);
            $table->string('color', 7)->nullable();
            $table->string('icon')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_activities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(['source_type', 'source_id']);
            $table->foreignId('resource_id')->constrained('booking_resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('activity');
            $table->enum('booking_type', ['group', 'open'])->default('group');
            $table->text('participation_info')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'rejected'])->default('confirmed');
            $table->boolean('exempt_from_limit')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'starts_at', 'ends_at']);
        });

        Schema::create('recurring_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('booking_resources')->cascadeOnDelete();
            $table->json('resource_ids')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('activity', 100);
            $table->enum('booking_type', ['group', 'open'])->default('group');
            $table->text('participation_info')->nullable();
            $table->text('notes')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('frequency_type', 50);
            $table->tinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('week_number')->nullable();
            $table->json('weekdays')->nullable();
            $table->tinyInteger('week_interval')->default(1);
            $table->tinyInteger('month_week')->nullable();
            $table->tinyInteger('month_day')->nullable();
            $table->unsignedTinyInteger('month_interval')->default(1);
            $table->tinyInteger('month')->nullable();
            $table->date('generate_until')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('last_conflicts')->nullable();
            $table->timestamps();
        });

        Schema::create('venue_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_name');
            $table->text('description')->nullable();
            $table->string('responsible_name');
            $table->string('responsible_phone', 50)->nullable();
            $table->string('responsible_email');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('participants')->default(0);
            $table->boolean('needs_overnight')->default(false);
            $table->string('needs_key', 20)->default('none');
            $table->boolean('has_alcohol')->default(false);
            $table->string('event_type', 10)->default('group');
            $table->text('event_type_reason')->nullable();
            $table->json('resource_ids');
            $table->string('activity', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('admin_notes')->nullable();
            $table->json('booking_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('area', 50);
            $table->string('subject', 500);
            $table->nullableMorphs('auditable');
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['area', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // sections.activity_id references booking_activities (created above)
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('activity_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('booking_activities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
            $table->dropColumn('activity_id');
        });
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('venue_applications');
        Schema::dropIfExists('recurring_bookings');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_activities');
        Schema::dropIfExists('booking_resources');
        Schema::dropIfExists('booking_categories');
    }
};
