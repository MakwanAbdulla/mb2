<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_texts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('default_text');
            $table->text('custom_text')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('custom_url', 500)->nullable();
            $table->string('group', 100);
            $table->string('field_label');
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_texts');
    }
};
