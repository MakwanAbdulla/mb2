<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_signers', function (Blueprint $table) {
            // Set when an external signer's personal data is pruned (signing:prune-external).
            // The row stays — it's referenced by requests — but holds no personal data.
            $table->timestamp('anonymized_at')->nullable()->after('provider_party_id');
        });
    }

    public function down(): void
    {
        Schema::table('signing_signers', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
