<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('notifiable');   // notifiable_type + notifiable_id (Order, Member, etc.)
            $table->string('notification_type');     // 'payment_reminder_1', 'renewal_reminder', etc.
            $table->string('recipient_email');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->index(['notification_type', 'notifiable_type', 'notifiable_id'], 'notif_log_type_notifiable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
