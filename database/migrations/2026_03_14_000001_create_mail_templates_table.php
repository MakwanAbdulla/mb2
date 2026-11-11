<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            // Machine-readable key, e.g. 'membership_invoice'
            $table->string('key')->unique();
            // Human label shown in admin UI
            $table->string('label');
            // Short description of when this mail is sent
            $table->string('description');
            // Subject line — supports {{variable}} placeholders
            $table->string('subject');
            // Body text — paragraph breaks preserved, {{variable}} substituted
            // Plain text only; structured data (tables, buttons) appended by mail class
            $table->text('body');
            // Admins can disable a template (mail class checks before sending)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('mail_templates')->insert([
            [
                'key'         => 'member_invitation',
                'label'       => 'Inbjudan — nytt familjemedlemskonto',
                'description' => 'Skickas när en familjeansvarig skapar en familjemedlem med e-postadress.',
                'subject'     => 'Välkommen till Armagedon — aktivera ditt konto',
                'body'        => implode("\n", [
                    'Hej {{fornamn}}!',
                    '',
                    'Du har lagts till som familjemedlem i Armagedon.',
                    '',
                    'Klicka på länken nedan för att välja ett lösenord och aktivera ditt konto:',
                    '',
                    '{{aktivera_url}}',
                    '',
                    'Länken är giltig i 60 minuter. Om du inte förväntade dig detta mejl kan du ignorera det.',
                    '',
                    'Välkommen!',
                ]),
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'key'         => 'family_invitation',
                'label'       => 'Inbjudan — familjegrupp',
                'description' => 'Skickas när en familjeansvarig bjuder in en befintlig medlem till sin familjegrupp.',
                'subject'     => 'Du har blivit inbjuden till en familjegrupp',
                'body'        => implode("\n", [
                    'Hej {{fornamn}}!',
                    '',
                    '{{avsandare}} har bjudit in dig till sin familjegrupp i Armagedon.',
                    '',
                    'Logga in på ditt konto och gå till "Min familj" för att acceptera eller avböja inbjudan.',
                    '',
                    'Inbjudan gäller tills du svarar eller tills den återkallas.',
                ]),
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'key'         => 'workspace_password',
                'label'       => 'Workspace-lösenord (nytt/återställt)',
                'description' => 'Skickas av admin när ett Workspace-konto skapas eller lösenordet återställs.',
                'subject'     => 'Ditt Google Workspace-konto – Armagedon',
                'body'        => "<p>Hej {{fornamn}},</p>\n<p>Ditt Google Workspace-konto har {{ny_eller_ny}}. Nedan ser du dina inloggningsuppgifter.</p>\n<p><strong>E-postadress:</strong> {{workspace}}<br><strong>Tillfälligt lösenord:</strong> {{losenord}}</p>\n<p>Du kommer att ombedas byta lösenord direkt när du loggar in första gången.</p>\n<p>Välkommen!<br>Armagedon</p>",
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('mail_templates')->whereIn('key', [
            'member_invitation', 'family_invitation', 'workspace_password',
        ])->delete();
        Schema::dropIfExists('mail_templates');
    }
};
