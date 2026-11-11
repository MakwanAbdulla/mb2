<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('tag_uid', 64)->nullable()
                ->comment('RFID UID in Paxton format (first 2 scanner digits stripped). Null until physically issued.');
            $table->string('status')->default('ordered')
                ->comment('ordered|active|lost|broken|deactivated');
            $table->unsignedInteger('price_ore')
                ->comment('Price snapshot at time of order, in öre');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete()
                ->comment('The order this tag was charged on');
            $table->timestamp('issued_at')->nullable()
                ->comment('When the physical tag was handed to the member');
            $table->timestamp('deactivated_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('paxton_sync_status')->default('pending')
                ->comment('pending|synced|failed — used by the Paxton agent');
            $table->text('paxton_sync_error')->nullable()
                ->comment('Last error message from the Paxton agent');
            $table->timestamp('paxton_synced_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index('paxton_sync_status');
            $table->unique('tag_uid');
        });

        Schema::create('tag_year_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('price_ore');
            $table->unsignedInteger('annual_fee_ore')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_year_prices');
        Schema::dropIfExists('member_tags');
    }
};
