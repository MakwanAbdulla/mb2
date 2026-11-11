<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imap_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(993);
            $table->enum('encryption', ['ssl', 'tls', 'none'])->default('ssl');
            $table->string('username');
            $table->text('password'); // encrypted
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->json('folders_progress')->nullable(); // { folderName: lastUid }
            $table->unsignedInteger('messages_imported')->default(0);
            $table->unsignedInteger('messages_total')->nullable();
            $table->string('current_folder')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imap_import_jobs');
    }
};
