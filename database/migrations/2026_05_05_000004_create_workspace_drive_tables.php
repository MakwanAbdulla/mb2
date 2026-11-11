<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_shared_drives', function (Blueprint $table) {
            $table->id();
            $table->string('google_drive_id')->nullable()->unique();
            $table->string('name');
            $table->string('member_role')->default('writer'); // reader | writer
            $table->boolean('public_access')->default(false);
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organizational_role_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workspace_drive_members', function (Blueprint $table) {
            $table->id();
            $table->string('google_drive_id');
            $table->string('email');
            $table->string('permission_id');
            $table->string('role')->default('writer');
            $table->string('source')->default('manual'); // manual | role | section
            $table->timestamps();

            $table->unique(['google_drive_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_drive_members');
        Schema::dropIfExists('workspace_shared_drives');
    }
};
