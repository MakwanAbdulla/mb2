<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('public');

            $table->foreignId('visibility_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->boolean('requires_membership')->default(false);
            $table->foreignId('access_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();

            $table->string('location_type')->default('external');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('venue_application_id')->nullable()->constrained('venue_applications')->nullOnDelete();
            $table->string('location_name')->nullable();
            $table->string('location_url')->nullable();

            $table->foreignId('organizer_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('organizer_external_name')->nullable();

            $table->string('registration_mode')->default('standard');
            $table->boolean('allow_ticket_transfer')->default(false);
            $table->string('waitlist_mode')->default('none');
            $table->string('admission_type')->default('on_payment');
            $table->datetime('registration_opens_at')->nullable();
            $table->datetime('registration_closes_at')->nullable();

            $table->boolean('notify_organizer_on_registration')->default(true);
            $table->boolean('notify_waitlist_on_opening')->default(true);
            $table->unsignedInteger('reminder_hours_before')->nullable();
            $table->text('registration_instructions')->nullable();
            $table->text('reminder_instructions')->nullable();

            $table->string('external_organizer_name')->nullable();
            $table->string('external_organizer_contact')->nullable();
            $table->unsignedInteger('external_fee_ore')->nullable();
            $table->datetime('external_invoiced_at')->nullable();
            $table->datetime('external_fee_paid_at')->nullable();
            $table->string('external_revenue_type')->default('none');
            $table->unsignedInteger('external_revenue_ore')->nullable();
            $table->unsignedTinyInteger('external_revenue_pct')->nullable();
            $table->unsignedInteger('external_settlement_ore')->nullable();
            $table->datetime('external_settled_at')->nullable();

            $table->unsignedBigInteger('registration_form_id')->nullable()
                ->comment('FK to forms — no constraint here, forms table created later');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_organizers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('helper');
            $table->timestamps();

            $table->unique(['event_id', 'member_id']);
        });

        // Bidirectional link: bookings can be tied to an event
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('id')->constrained('events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropColumn('event_id');
        });
        Schema::dropIfExists('event_organizers');
        Schema::dropIfExists('events');
    }
};
