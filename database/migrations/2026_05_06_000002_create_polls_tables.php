<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->morphs('voteable');             // Submission | MeetingAgendaItem
            $table->string('question');
            $table->string('type', 20)->default('yes_no_abstain')
                ->comment('yes_no_abstain | yes_no | free');
            $table->string('status', 20)->default('pending')
                ->comment('pending | open | closed | manual');
            $table->unsignedInteger('eligible_count')->default(0)
                ->comment('Låses när omröstningen öppnas');
            $table->string('result', 20)->nullable()
                ->comment('approved | rejected | tied | no_quorum');
            $table->unsignedInteger('result_yes')->default(0);
            $table->unsignedInteger('result_no')->default(0);
            $table->unsignedInteger('result_abstain')->default(0);
            $table->string('result_note')->nullable()
                ->comment('Fri text: acklamation, enhälligt, etc.');
            $table->timestamp('deadline')->nullable()
                ->comment('Satt för per capsulam-omröstningar');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedTinyInteger('sort_order')->default(0);
        });

        Schema::create('poll_ballots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('users');
            $table->foreignId('option_id')->nullable()->constrained('poll_options')->nullOnDelete();
            $table->timestamp('voted_at')->nullable();

            $table->unique(['poll_id', 'voter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_ballots');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
