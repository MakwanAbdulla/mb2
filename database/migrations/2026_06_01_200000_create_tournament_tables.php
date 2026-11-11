<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('event_occurrence_id')->nullable()->constrained('event_occurrences')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('status')->default('draft'); // draft|registration|ongoing|completed|cancelled
            $table->string('participant_type')->default('individual'); // individual|team
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->boolean('is_public')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('tournament_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->unsignedTinyInteger('phase_number');
            $table->string('name');
            $table->string('format'); // single_elim|double_elim|round_robin|swiss|pod_swiss
            $table->string('status')->default('pending'); // pending|ongoing|completed
            $table->unsignedTinyInteger('players_per_match')->default(2);
            $table->unsignedTinyInteger('rounds_count')->nullable();
            $table->unsignedTinyInteger('match_points_win')->default(3);
            $table->unsignedTinyInteger('match_points_draw')->default(1);
            $table->unsignedTinyInteger('match_points_loss')->default(0);
            $table->boolean('uses_game_points')->default(false);
            $table->unsignedSmallInteger('advancement_count')->nullable();
            $table->unsignedTinyInteger('group_count')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('tournament_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tournament_team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('tournament_teams')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->boolean('is_captain')->default(false);
            $table->primary(['team_id', 'member_id']);
        });

        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('tournament_teams')->nullOnDelete();
            $table->unsignedSmallInteger('seed')->nullable();
            $table->string('status')->default('registered'); // registered|checked_in|withdrawn|disqualified
            $table->timestamps();
        });

        Schema::create('tournament_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained('tournament_phases')->cascadeOnDelete();
            $table->unsignedTinyInteger('round_number');
            $table->string('status')->default('pending'); // pending|ongoing|completed
            $table->timestamps();
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('tournament_rounds')->cascadeOnDelete();
            $table->string('group_name')->nullable();
            $table->unsignedSmallInteger('bracket_position')->nullable();
            $table->string('bracket_side')->nullable(); // winners|losers
            $table->unsignedBigInteger('next_match_id')->nullable();
            $table->unsignedBigInteger('loser_next_match_id')->nullable();
            $table->string('status')->default('pending'); // pending|ongoing|completed|bye
            $table->timestamps();

            $table->foreign('next_match_id')->references('id')->on('tournament_matches')->nullOnDelete();
            $table->foreign('loser_next_match_id')->references('id')->on('tournament_matches')->nullOnDelete();
        });

        Schema::create('tournament_match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('tournament_matches')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tournament_participants')->cascadeOnDelete();
            $table->integer('game_score')->nullable();
            $table->unsignedTinyInteger('match_points')->nullable();
            $table->unsignedTinyInteger('placement')->nullable();
            $table->boolean('is_winner')->default(false);
        });

        Schema::create('tournament_standings', function (Blueprint $table) {
            $table->foreignId('phase_id')->constrained('tournament_phases')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tournament_participants')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank')->default(0);
            $table->integer('match_points')->default(0);
            $table->integer('game_points')->default(0);
            $table->unsignedSmallInteger('wins')->default(0);
            $table->unsignedSmallInteger('losses')->default(0);
            $table->unsignedSmallInteger('draws')->default(0);
            $table->primary(['phase_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_standings');
        Schema::dropIfExists('tournament_match_participants');
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_rounds');
        Schema::dropIfExists('tournament_participants');
        Schema::dropIfExists('tournament_team_members');
        Schema::dropIfExists('tournament_teams');
        Schema::dropIfExists('tournament_phases');
        Schema::dropIfExists('tournaments');
    }
};
