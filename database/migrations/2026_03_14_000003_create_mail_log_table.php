<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();
            $table->string('mail_type')->nullable();
            $table->string('mailable_class')->nullable();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->longText('body_html')->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->string('tracking_token')->nullable()->unique();
            $table->timestamp('first_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->string('delivery_status')->default('sent');
            $table->text('delivery_error')->nullable();
            $table->timestamps();

            $table->index(['recipient_email', 'sent_at']);
            $table->index(['member_id', 'sent_at']);
            $table->index('mail_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
