<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_xp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('giver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('source_type'); // membership_renewal|event_checkin|event_registration|profile_avatar|profile_complete|peer_kudos|booking
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('points');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('member_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('badge_key');
            $table->timestamp('awarded_at')->useCurrent();
            $table->unique(['member_id', 'badge_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_badges');
        Schema::dropIfExists('member_xp_logs');
    }
};
