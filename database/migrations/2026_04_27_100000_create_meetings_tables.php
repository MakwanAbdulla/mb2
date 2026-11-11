<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Null = global/föreningen, satt = sektionsspecifik');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('meeting_type', 50)->default('ordinary')
                ->comment('ordinary|extraordinary|annual_general|section|other');
            $table->boolean('is_extraordinary')->default(false);
            $table->unsignedSmallInteger('default_notice_days')->default(14)
                ->comment('Hur många dagar i förväg kallelse ska skickas');
            $table->boolean('include_absences_in_minutes')->default(true)
                ->comment('Ska frånvarande synas i protokollet?');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('meeting_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('meeting_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title');
            $table->text('description')->nullable()
                ->comment('Instruktion, stadgetext eller standardformulering');
            $table->text('minutes_template')->nullable()
                ->comment('Förberedande protokolltext — kopieras till agenda vid mötesgenerering');
            $table->string('item_type', 30)->default('standard')
                ->comment('standard|vote|election|info');
            $table->boolean('is_required')->default(false)
                ->comment('Stadgereglerade punkter kan inte tas bort');
            $table->timestamps();
        });

        Schema::create('meeting_template_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('meeting_templates')->cascadeOnDelete();
            $table->string('section_role_keyword')
                ->comment('Matchas case-insensitivt mot SectionRole.title, t.ex. "ordförande"');
            $table->string('meeting_role', 30)
                ->comment('chair|secretary|adjuster|attendee');
            $table->timestamps();
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('meeting_templates')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('meeting_type', 50)->default('ordinary');
            $table->boolean('is_extraordinary')->default(false);
            $table->string('status', 30)->default('draft')
                ->comment('draft|scheduled|ongoing|closed|cancelled');
            $table->dateTime('scheduled_at');
            $table->dateTime('ends_at')->nullable();
            $table->date('motion_deadline')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('notice_mailing_id')->nullable()->constrained('mailings')->nullOnDelete();
            $table->boolean('include_absences_in_minutes')->default(true);
            $table->timestamp('approved_at')->nullable()
                ->comment('När protokollet är justerat (fysiskt underskrivet)');
            $table->timestamp('published_at')->nullable()
                ->comment('När protokollet är publicerat och synligt för medlemmar');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('minutes_template')->nullable()
                ->comment('Förberedande protokolltext kopierad från mallen');
            $table->string('item_type', 30)->default('standard');
            $table->boolean('is_required')->default(false);
            $table->string('source_gmail_id')->nullable()->index();
            $table->string('source_gmail_subject')->nullable();
            $table->string('source_gmail_from', 320)->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_minutes_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('agenda_item_id')->constrained('meeting_agenda_items')->cascadeOnDelete();
            $table->longText('content')->nullable()
                ->comment('Löpande protokolltext');
            $table->text('decision')->nullable()
                ->comment('Formell beslutssats, null om ingen beslutspunkt');
            $table->boolean('is_decision')->default(false);
            $table->timestamps();
        });

        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable()
                ->comment('Fritext-fallback för icke-medlemmar');
            $table->string('email')->nullable();
            $table->string('role', 30)->default('attendee')
                ->comment('attendee|chair|secretary|adjuster|proxy');
            $table->boolean('invited')->default(true)
                ->comment('Stod på kallelsen');
            $table->boolean('present')->nullable()
                ->comment('Null = ej registrerad, true = närvarande, false = frånvarande');
            $table->foreignId('proxy_for_member_id')->nullable()->constrained('members')->nullOnDelete()
                ->comment('Ombud för annan medlem');
            $table->boolean('voting_rights')->default(true);
            $table->timestamp('membership_verified_at')->nullable();
            $table->boolean('membership_valid')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'member_id']);
            $table->index(['meeting_id', 'present']);
        });

        Schema::create('meeting_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete()
                ->comment('Null = mötesbilaga, satt = underlag till specifik punkt');
            $table->string('type', 30)->default('attachment')
                ->comment('signed_minutes|attachment');
            $table->boolean('is_protocol_attachment')->default(false)
                ->comment('True = ingår i protokollet och syns för alla; false = internt underlag');
            $table->string('label');
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_documents');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meeting_minutes_items');
        Schema::dropIfExists('meeting_agenda_items');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('meeting_template_role_mappings');
        Schema::dropIfExists('meeting_template_items');
        Schema::dropIfExists('meeting_templates');
    }
};
