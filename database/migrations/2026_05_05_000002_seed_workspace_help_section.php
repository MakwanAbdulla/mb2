<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('help_sections')->where('slug', 'google-workspace')->exists()) {
            return;
        }

        DB::table('help_sections')->insert([
            'slug'                => 'google-workspace',
            'title'               => 'Google Workspace och @armagedon.se-konton',
            'group_label'         => 'Konto & inloggning',
            'show_public'         => false,
            'show_auth'           => true,
            'required_permission' => null,
            'sort_order'          => 100,
            'content'             => '<h2>Vad är ett @armagedon.se-konto?</h2>
<p>Styrelseledamöter, funktionärer och andra förtroendevalda kan få ett personligt e-postkonto i föreningens Google Workspace, t.ex. <code>fornamn.efternamn@armagedon.se</code>. Kontot används för föreningsrelaterad kommunikation.</p>

<h2>Delegerade postlådor</h2>
<p>Förutom ditt personliga konto kan du ha tillgång till gemensamma funktionskonton, t.ex. <code>ekonomi@armagedon.se</code> eller <code>kansli@armagedon.se</code>. Dessa kallas <em>delegerade postlådor</em> — du kan läsa och svara på mejl som om du vore kontot, men mejlen är gemensamma.</p>
<p>Dina delegerade postlådor visas under <strong>Postlådor</strong> i kontomenyn (din profilbild uppe till höger).</p>

<h2>Öppna en delegerad postlåda</h2>
<p>Länkarna i kontomenyn öppnar rätt postlåda direkt i Gmail — men <strong>du måste vara inloggad med ditt @armagedon.se-konto i webbläsaren</strong>. Använder du normalt din privata Gmail behöver du lägga till föreningskontot som ett sekundärt konto:</p>
<ol>
  <li>Gå till <a href="https://mail.google.com" target="_blank">mail.google.com</a> eller någon annan Google-tjänst.</li>
  <li>Klicka på din profilbild uppe till höger.</li>
  <li>Välj <strong>Lägg till ett annat konto</strong>.</li>
  <li>Logga in med <code>dittnamn@armagedon.se</code> och ditt lösenord.</li>
</ol>
<p>Därefter behöver du inte göra detta igen — båda kontona är tillgängliga i webbläsaren och länkarna i Membrum öppnar automatiskt rätt postlåda.</p>

<h2>Jag har inte fått något @armagedon.se-konto</h2>
<p>Kontakta styrelsen om du behöver ett föreningskonto i samband med ett förtroendeuppdrag.</p>',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('help_sections')->where('slug', 'google-workspace')->delete();
    }
};
