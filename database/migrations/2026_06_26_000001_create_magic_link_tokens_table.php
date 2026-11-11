<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        DB::table('mail_templates')->updateOrInsert(
            ['key' => 'magic_link'],
            [
                'label' => 'Inloggningslänk (magic link)',
                'description' => 'Skickas när en användare begär en lösenordsfri inloggningslänk.',
                'subject' => 'Din inloggningslänk till {{app_name}}',
                'body' => implode("\n\n", [
                    'Hej {{fornamn}}!',
                    'Du (eller någon annan) begärde en inloggningslänk till {{app_name}}. Klicka på länken nedan för att logga in — den gäller i 15 minuter.',
                    '{{login_url}}',
                    'Om du inte begärde den här länken kan du ignorera detta mejl. Ditt konto är säkert.',
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
        DB::table('mail_templates')->where('key', 'magic_link')->delete();
    }
};
