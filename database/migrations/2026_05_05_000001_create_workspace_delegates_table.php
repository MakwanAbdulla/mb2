<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_delegates', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox');
            $table->string('delegate_email');
            $table->string('status')->default('accepted');
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['mailbox', 'delegate_email']);
            $table->index('delegate_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_delegates');
    }
};
