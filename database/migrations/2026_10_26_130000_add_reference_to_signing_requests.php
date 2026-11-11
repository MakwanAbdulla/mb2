<?php

use App\Models\Signing\SigningRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_requests', function (Blueprint $table) {
            // Short hash of id + created_at, appended to the document name at DocSign.
            // Not unique: six hex characters can collide, which is harmless.
            $table->string('reference', 6)->nullable()->after('name')->index();
        });

        DB::table('signing_requests')->whereNull('reference')->orderBy('id')->each(function ($row) {
            DB::table('signing_requests')->where('id', $row->id)->update([
                'reference' => SigningRequest::computeReference($row->id, Carbon::parse($row->created_at)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('signing_requests', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn('reference');
        });
    }
};
