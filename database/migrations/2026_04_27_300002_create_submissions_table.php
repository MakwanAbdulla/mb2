<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->default('issue')
                ->comment('issue|motion');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 30)->default('submitted')
                ->comment('submitted|under_review|on_agenda|resolved|rejected');
            $table->string('source_gmail_id')->nullable();
            $table->string('source_gmail_subject')->nullable();
            $table->string('source_gmail_from')->nullable();
            $table->unsignedBigInteger('source_mailbox_id')->nullable()
                ->comment('FK to inbox_mailboxes (no constraint — table created later)');
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Null = styrelsen/föreningen');
            $table->foreignId('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete()
                ->comment('Satt när ärendet lagts till på en dagordning');
            $table->text('board_response')->nullable()
                ->comment('Styrelsens yttrande — används primärt för motioner');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['section_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
