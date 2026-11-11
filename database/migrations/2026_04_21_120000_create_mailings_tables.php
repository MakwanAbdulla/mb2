<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('subject');
            $table->longText('body_html');
            $table->foreignId('created_by')->constrained('users');
            $table->string('permission_required')->nullable()
                ->comment('Extra permission needed to use this template. Null = anyone with mailings.manage');
            $table->timestamps();
        });

        Schema::create('mailings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailing_template_id')->nullable()->constrained('mailing_templates')->nullOnDelete();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('subject');
            $table->longText('body_html');
            $table->string('status')->default('draft')
                ->comment('draft|scheduled|sending|sent|cancelled');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();
        });

        Schema::create('mailing_recipients', function (Blueprint $table) {
            $table->foreignId('mailing_id')->constrained('mailings')->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->primary(['mailing_id', 'email']);
        });

        Schema::create('mailing_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailing_id')->nullable()->constrained('mailings')->nullOnDelete();
            $table->timestamp('unsubscribed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('mailing_category_optouts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->timestamp('opted_out_at')->useCurrent();
            $table->timestamps();

            $table->unique(['email', 'category']);
            $table->index('email');
        });

        // mailing_id FK on mail_log (mail_log created earlier)
        Schema::table('mail_log', function (Blueprint $table) {
            $table->foreignId('mailing_id')
                ->nullable()
                ->after('user_id')
                ->constrained('mailings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_log', function (Blueprint $table) {
            $table->dropForeign(['mailing_id']);
            $table->dropColumn('mailing_id');
        });
        Schema::dropIfExists('mailing_category_optouts');
        Schema::dropIfExists('mailing_unsubscribes');
        Schema::dropIfExists('mailing_recipients');
        Schema::dropIfExists('mailings');
        Schema::dropIfExists('mailing_templates');
    }
};
