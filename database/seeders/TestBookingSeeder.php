<?php

namespace Database\Seeders;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Content\Notice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestBookingSeeder extends Seeder
{
    private const TITLES = [
        'Brädspel'                        => ['Spelkväll', 'Kampanjkväll', 'Turneringskväll', 'Speldag', 'Lärkväll'],
        'Figurspel'                       => ['Träningskväll', 'Spelkväll', 'Kampanjkväll', 'Turneringskväll', 'Målardag'],
        'Kortspel'                        => ['Spelkväll', 'Draft-kväll', 'Turneringskväll', 'Lärkväll', 'Kampanjkväll'],
        'Krigsspel'                       => ['Spelkväll', 'Kampanjkväll', 'Scenariokväll', 'Träning', 'Speldag'],
        'Måleri & Modellbygge'            => ['Målardag', 'Hobbykväll', 'Workshop', 'Modellbygge', 'Målarworkshop'],
        'Möten & Organisationsutveckling' => ['Möte', 'Styrelsemöte', 'Planeringsmöte', 'Arbetsgruppsmöte', 'Uppföljningsmöte'],
        'Häng på Geddon'                  => ['Häng', 'Fredagshäng', 'Lördagshäng', 'Spontanhäng', 'Spelaftonhäng'],
        'Annat'                           => ['Kväll', 'Dag', 'Träff', 'Samling', 'Event'],
    ];

    private const DURATIONS = [60, 90, 120, 150, 180, 240];

    public function run(): void
    {
        $rooms = BookingResource::where('active', true)->get();

        if ($rooms->isEmpty()) {
            $this->command->warn('Inga rum hittades. Kör BookingDataSeeder först.');
            return;
        }

        $admins  = User::role('admin')->get();
        $members = User::whereHas('member', fn ($q) => $q->where('status', 'active'))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();

        if ($admins->isEmpty() && $members->isEmpty()) {
            $this->command->warn('Inga användare hittades. Kör db:seed (inklusive TestUserSeeder) först.');
            return;
        }

        $rangeStart = today()->subDays(3);
        $rangeEnd   = today()->addWeeks(4);
        $activities = array_keys(self::TITLES);
        $created    = 0;
        $skipped    = 0;

        // 5 bokningar per admin
        foreach ($admins as $admin) {
            for ($i = 0; $i < 5; $i++) {
                if ($this->createRandomBooking($admin, $rooms, $rangeStart, $rangeEnd, $activities)) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        // 80 bokningar fördelade på slumpmässiga medlemmar
        if ($members->isNotEmpty()) {
            $membersArr = $members->values()->all();
            for ($i = 0; $i < 80; $i++) {
                $user = $membersArr[array_rand($membersArr)];
                if ($this->createRandomBooking($user, $rooms, $rangeStart, $rangeEnd, $activities)) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        $this->command->info("Skapade {$created} testbokningar ({$skipped} hoppades över p.g.a. konflikt).");

        $this->seedNotices();
    }

    private function seedNotices(): void
    {
        $armagedon = User::where('email', 'foreningen@armagedon.local')->first();

        if (! $armagedon) {
            $this->command->warn('Systemanvändaren saknas, hoppar över notiser. Kör BookingDataSeeder först.');
            return;
        }

        $notices = [
            ['title' => 'Dörrlåset till Röda rummet trasigt',   'message' => 'Låset till Röda rummet är ur funktion. Kontakta styrelsen eller ta hjälp av personal på plats för att komma in. Vi arbetar på en lösning.', 'offset' => 0, 'length' => 4,  'type' => 'error',   'is_public' => true],
            ['title' => 'Nytt kaffemaskin installerad',          'message' => 'Vi har fått en ny kaffemaskin i köket! Kaffe och te är gratis för alla medlemmar under mars. Välkommen att fika.',                        'offset' => 0, 'length' => 5,  'type' => 'success', 'is_public' => false],
            ['title' => 'Parkeringsförbud 10–11 mars',           'message' => 'På grund av gatuarbete råder parkeringsförbud på Storgatan utanför lokalen 10–11 mars. Parkera i stället på Järntorget.',                 'offset' => 0, 'length' => 4,  'type' => 'warning', 'is_public' => true],
            ['title' => 'Välkommen till Armagedon!',             'message' => 'Hej och välkommen! Här kan du boka rum och hålla koll på vad som händer i föreningen. Om du har frågor är du alltid välkommen att höra av dig till styrelsen.', 'offset' => 0, 'length' => 7, 'type' => 'info', 'is_public' => true],
            ['title' => 'Spelkväll inställd 6/3',                'message' => 'På grund av lokalbyte är tisdagens figurspelskväll 6 mars inställd. Vi ses som vanligt veckan efter!',                                    'offset' => 3, 'length' => 3,  'type' => 'warning', 'is_public' => true],
            ['title' => 'Medlemsavgift 2026',                    'message' => 'Påminnelse: Årets medlemsavgift ska betalas senast 31 mars. Betala till plusgiro 12 34 56-7 och ange ditt namn som referens.',             'offset' => 4, 'length' => 14, 'type' => 'warning', 'is_public' => false],
            ['title' => 'Workshop: Figurmålning för nybörjare',  'message' => 'Lördag 8 mars håller vi en nybörjarworkshop i figurmålning. Alla material ingår. Anmäl dig till workshop@armagedon.se senast 5 mars.',    'offset' => 5, 'length' => 5,  'type' => 'info',    'is_public' => true],
            ['title' => 'Nytt spel i biblioteket',               'message' => 'Vi har fått in Twilight Imperium 4:e upplagan och Gloomhaven: Frosthaven till spelbiblioteket. Boka ett rum och testa!',                   'offset' => 6, 'length' => 10, 'type' => 'success', 'is_public' => false],
            ['title' => 'Städdag 15 mars',                       'message' => 'Vi har städdag på lokalen lördagen 15 mars kl 10–13. Alla medlemmar uppmanas att hjälpa till. Fika serveras!',                             'offset' => 10,'length' => 6,  'type' => 'info',    'is_public' => false],
            ['title' => 'Turneringen börjar snart',              'message' => 'Anmälan till vårens Warhammer 40k-turnering stänger 16 mars. Det är fortfarande platser kvar – anmäl dig via hemsidan!',                   'offset' => 11,'length' => 5,  'type' => 'info',    'is_public' => true],
            ['title' => 'Lokalen stängd påskhelgen',             'message' => 'Observera att lokalen är stängd fredag–måndag under påskhelgen (18–21 april). Planera era bokningar därefter.',                            'offset' => 13,'length' => 4,  'type' => 'warning', 'is_public' => true],
            ['title' => 'Ny öppettid på onsdagar',               'message' => 'Från och med 19 mars öppnar vi lokalen även på onsdagar kl 17–22. Passa på att boka rum för träffar!',                                    'offset' => 14,'length' => 10, 'type' => 'success', 'is_public' => true],
            ['title' => 'Spelbiblioteket – returndatum',         'message' => 'Påminnelse till alla som lånat spel: returndatumet är 22 mars. Lämna tillbaka spelen i gott skick.',                                        'offset' => 15,'length' => 5,  'type' => 'warning', 'is_public' => false],
            ['title' => 'BSK 2026 – boka in datumet!',           'message' => 'Borås Spelkonvent 2026 går av stapeln 24–25 maj. Förhandsbokning öppnar 1 april. Mer info kommer på hemsidan.',                            'offset' => 16,'length' => 8,  'type' => 'info',    'is_public' => true],
            ['title' => 'Styrelsemöte 26 mars – öppet för frågor','message'=> 'Styrelsemötet är öppet för observatörer 26 mars kl 19. Har du frågor till styrelsen? Maila in dem senast 23 mars.',                       'offset' => 18,'length' => 4,  'type' => 'info',    'is_public' => false],
            ['title' => 'Uppdaterade bokningsregler',            'message' => 'Vi har uppdaterat reglerna för rumsbokning. Maxbokningslängden är nu 6 timmar per tillfälle och avbokning ska ske senast 24 h i förväg.',  'offset' => 20,'length' => 14, 'type' => 'info',    'is_public' => true],
            ['title' => 'Warhammer Age of Sigmar – startdag',    'message' => 'Är du nyfiken på Age of Sigmar? Vi startar en nybörjargrupp 29 mars. Kontakta figurspelssektionen för mer info.',                          'offset' => 22,'length' => 5,  'type' => 'info',    'is_public' => true],
            ['title' => 'April: spelmaraton-helg',               'message' => 'Helgen 5–6 april anordnar vi en 24-timmars spelmaraton! Boka platser nu, antalet är begränsat. Mer info skickas ut via mejl.',             'offset' => 24,'length' => 6,  'type' => 'info',    'is_public' => true],
        ];

        $count = 0;
        foreach ($notices as $n) {
            $startsAt = today()->addDays($n['offset']);
            $endsAt   = $startsAt->copy()->addDays($n['length']);

            Notice::create([
                'user_id'   => $armagedon->id,
                'title'     => $n['title'],
                'message'   => $n['message'],
                'starts_at' => $startsAt,
                'ends_at'   => $endsAt,
                'duration'  => 10,
                'type'      => $n['type'],
                'is_public' => $n['is_public'],
            ]);
            $count++;
        }

        $this->command->info("Skapade {$count} testnotiser.");
    }

    private function createRandomBooking(
        User $user,
        \Illuminate\Support\Collection $rooms,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        array $activities
    ): bool {
        $room     = $rooms->random();
        $activity = $activities[array_rand($activities)];
        $titles   = self::TITLES[$activity];
        $title    = $titles[array_rand($titles)];
        $duration = self::DURATIONS[array_rand(self::DURATIONS)];

        $totalDays = $rangeStart->diffInDays($rangeEnd);
        $date      = $rangeStart->copy()->addDays(rand(0, $totalDays));

        // Slumpmässig starttid på hel- eller halvtimme, 08:00–21:30
        $slot      = rand(8 * 2, 21 * 2 + 1);
        $startHour = intdiv($slot, 2);
        $startMin  = ($slot % 2) * 30;

        $startsAt = $date->copy()->setHour($startHour)->setMinute($startMin)->setSecond(0);
        $endsAt   = $startsAt->copy()->addMinutes($duration);

        // Klipp sluttid till midnatt
        $midnight = $date->copy()->endOfDay()->addSecond();
        if ($endsAt > $midnight) {
            $endsAt = $midnight;
        }

        $conflict = Booking::where('resource_id', $room->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($conflict) {
            return false;
        }

        Booking::create([
            'resource_id'  => $room->id,
            'user_id'      => $user->id,
            'title'        => $title,
            'activity'     => $activity,
            'booking_type' => 'group',
            'starts_at'    => $startsAt,
            'ends_at'      => $endsAt,
            'status'       => 'confirmed',
        ]);

        return true;
    }
}
