<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_role_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_role_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('period_type')->default('calendar_year'); // calendar_year | custom
            $table->smallInteger('year')->nullable();
            $table->date('custom_start')->nullable();
            $table->date('custom_end')->nullable();
            $table->date('forming_start')->nullable();
            $table->date('active_start');
            $table->date('active_end');
            $table->date('reporting_end')->nullable();
            $table->string('status')->default('forming'); // forming | active | reporting | closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_role_periods');
    }
};
