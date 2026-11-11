<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'member_id']);
        });

        Schema::table('event_invitations', function (Blueprint $table) {
            $table->foreignId('invited_by_member_id')
                ->nullable()
                ->after('member_id')
                ->constrained('members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_interests');
        Schema::table('event_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_member_id');
        });
    }
};
