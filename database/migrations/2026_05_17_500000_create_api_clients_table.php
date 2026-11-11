<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_service_account')->default(false)->after('ui_theme');
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->uuid('client_id')->unique();
            $table->string('client_secret', 100);   // bcrypt hash
            $table->json('scopes')->nullable();      // array of permission names, null = all api.access allows
            $table->boolean('enabled')->default(true);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_service_account');
        });
    }
};
