<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('category')->default('general');
            $table->string('label')->nullable();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $settings = [
            ['key' => 'sections.groups_can_have_subgroups', 'category' => 'sections', 'label' => 'Grupper kan ha undergrupper', 'value' => '1', 'type' => 'boolean', 'description' => 'Om aktiverat kan grupper (undersektioner) ha egna undergrupper.'],
            ['key' => 'sections.room_booking_enabled', 'category' => 'booking', 'label' => 'Sektioner/grupper kan boka rum', 'value' => '0', 'type' => 'boolean', 'description' => 'Masterswitch — tillåter sektioner och grupper att boka rum för sin verksamhet.'],
            ['key' => 'sections.max_bookings', 'category' => 'booking', 'label' => 'Max bokningar per sektion', 'value' => '2', 'type' => 'integer', 'description' => 'Hur många aktiva bokningar en sektion (toppnivå) kan ha samtidigt.'],
            ['key' => 'sections.groups_max_bookings', 'category' => 'booking', 'label' => 'Max bokningar per grupp', 'value' => '1', 'type' => 'integer', 'description' => 'Hur många aktiva bokningar en grupp (undernivå) kan ha samtidigt.'],
        ];
        foreach ($settings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', ['sections.groups_can_have_subgroups', 'sections.room_booking_enabled', 'sections.max_bookings', 'sections.groups_max_bookings'])
            ->delete();
        Schema::dropIfExists('system_settings');
    }
};
