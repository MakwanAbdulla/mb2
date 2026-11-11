<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Membership\Member;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Sign the payload the way Discord does and post it to the endpoint.
 * A different body can be sent than the one that was signed.
 */
function dicPost(array $payload, string $secretKey, ?array $sentPayload = null, ?int $timestamp = null): TestResponse
{
    $signedBody = json_encode($payload);
    $timestamp = (string) ($timestamp ?? time());
    $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$signedBody, $secretKey));

    return test()->call('POST', '/discord/interactions', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($sentPayload ?? $payload));
}

/**
 * An application command as Discord sends it from a server, optionally
 * from a specific Discord user.
 */
function dicCommand(string $name, array $options = [], ?string $discordId = null): array
{
    $command = [
        'type' => 2,
        'data' => [
            'name' => $name,
            'options' => collect($options)->map(fn ($value, $key) => ['name' => $key, 'type' => 3, 'value' => $value])->values()->all(),
        ],
    ];

    if ($discordId) {
        $command['member'] = ['user' => ['id' => $discordId]];
    }

    return $command;
}

function dicLinkedUser(string $discordId, bool $activeMember = true): User
{
    $user = User::factory()->create(['discord_id' => $discordId]);

    Member::factory()->create([
        'user_id' => $user->id,
        'status' => $activeMember ? Member::STATUS_ACTIVE : Member::STATUS_PENDING,
    ]);

    return $user;
}

function dicBooking(CarbonInterface $start): Booking
{
    $resource = BookingResource::firstOrCreate(
        ['slug' => 'vita-rummet'],
        ['name' => 'Vita rummet', 'active' => true, 'color' => '#000000'],
    );

    return Booking::create([
        'resource_id' => $resource->id,
        'user_id' => User::factory()->create()->id,
        'title' => 'Spelkväll',
        'activity' => 'Brädspel',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(3),
        'status' => 'confirmed',
    ]);
}

function dicEvent(string $title, string $visibility, CarbonInterface $start): EventOccurrence
{
    $event = Event::factory()->create([
        'title' => $title,
        'status' => Event::STATUS_PUBLISHED,
        'visibility' => $visibility,
        'location_name' => 'Stora salen',
    ]);

    return EventOccurrence::factory()->create([
        'event_id' => $event->id,
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'cancelled_at' => null,
        'location_override' => null,
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    config(['services.discord.public_key' => bin2hex(sodium_crypto_sign_publickey($keypair))]);
});

test('answers a ping with a pong', function () {
    dicPost(['type' => 1], $this->secretKey)
        ->assertOk()
        ->assertExactJson(['type' => 1]);
});

test('rejects a request signed with another key', function () {
    $otherKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    dicPost(['type' => 1], $otherKey)->assertUnauthorized();
});

test('rejects a request without signature headers', function () {
    $this->postJson('/discord/interactions', ['type' => 1])->assertUnauthorized();
});

test('rejects a request whose body was changed after signing', function () {
    dicPost(dicCommand('idag'), $this->secretKey, sentPayload: dicCommand('vecka'))->assertUnauthorized();
});

test('rejects a request with an old timestamp', function () {
    dicPost(['type' => 1], $this->secretKey, timestamp: time() - 600)->assertUnauthorized();
});

test('/idag shows today\'s bookings to the caller only', function () {
    dicLinkedUser('111');
    dicBooking(now()->setTime(18, 0));

    dicPost(dicCommand('idag', discordId: '111'), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.flags', 64)
        ->assertJsonPath('data.allowed_mentions.parse', [])
        ->assertJsonPath('data.embeds.0.description', fn (string $description) => str_contains($description, '18:00–21:00 Vita rummet: Spelkväll'));
});

test('a linked member sees booking titles and members-only events', function () {
    dicLinkedUser('111');
    dicBooking(now()->setTime(18, 0));
    dicEvent('Årsmöte', Event::VISIBILITY_MEMBERS_ONLY, now()->setTime(12, 0));

    dicPost(dicCommand('idag', discordId: '111'), $this->secretKey)
        ->assertOk()
        ->assertJsonCount(1, 'data.embeds')
        ->assertJsonPath('data.embeds.0.description', fn (string $description) => str_contains($description, 'Vita rummet: Spelkväll')
            && str_contains($description, 'Årsmöte'));
});

test('someone without a linked account only sees what is public and gets a link hint', function () {
    dicBooking(now()->setTime(18, 0));
    dicEvent('Turnering', Event::VISIBILITY_PUBLIC, now()->setTime(12, 0));
    dicEvent('Årsmöte', Event::VISIBILITY_MEMBERS_ONLY, now()->setTime(14, 0));

    dicPost(dicCommand('idag', discordId: '999'), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.embeds.0.description', fn (string $description) => str_contains($description, '18:00–21:00 Vita rummet')
            && ! str_contains($description, 'Spelkväll')
            && str_contains($description, 'Turnering')
            && ! str_contains($description, 'Årsmöte'))
        ->assertJsonPath('data.embeds.1.description', fn (string $hint) => str_contains($hint, 'Koppla ditt Discord-konto')
            && str_contains($hint, route('auth.discord.link')));
});

test('a linked account without an active membership only sees what is public', function () {
    dicLinkedUser('222', activeMember: false);
    dicBooking(now()->setTime(18, 0));

    dicPost(dicCommand('idag', discordId: '222'), $this->secretKey)
        ->assertOk()
        ->assertJsonCount(2, 'data.embeds')
        ->assertJsonPath('data.embeds.0.description', fn (string $description) => ! str_contains($description, 'Spelkväll'));
});

test('no link hint when nothing was left out', function () {
    dicEvent('Turnering', Event::VISIBILITY_PUBLIC, now()->setTime(12, 0));

    dicPost(dicCommand('idag'), $this->secretKey)
        ->assertOk()
        ->assertJsonCount(1, 'data.embeds');
});

test('/vecka shows the seven day overview', function () {
    dicPost(dicCommand('vecka'), $this->secretKey)
        ->assertOk()
        ->assertJsonCount(7, 'data.embeds.0.fields');
});

test('/vecka shows booking titles to a linked member', function () {
    dicLinkedUser('111');
    dicBooking(now()->addDay()->setTime(18, 0));

    dicPost(dicCommand('vecka', discordId: '111'), $this->secretKey)
        ->assertOk()
        ->assertJsonCount(1, 'data.embeds')
        ->assertJsonPath('data.embeds.0.fields.1.value', fn (string $value) => str_contains($value, 'Vita rummet: Spelkväll'));
});

test('/vecka hides booking titles from others and adds the link hint', function () {
    dicBooking(now()->addDay()->setTime(18, 0));

    dicPost(dicCommand('vecka'), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.embeds.0.fields.1.value', fn (string $value) => str_contains($value, '18:00–21:00 Vita rummet')
            && ! str_contains($value, 'Spelkväll'))
        ->assertJsonPath('data.embeds.1.description', fn (string $hint) => str_contains($hint, 'Koppla ditt Discord-konto'));
});

test('/ledigt shows the requested day', function (string $format) {
    $day = now()->addDays(3);
    dicLinkedUser('111');
    dicBooking($day->copy()->setTime(18, 0));

    dicPost(dicCommand('ledigt', ['datum' => $day->format($format)], '111'), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.embeds.0.title', fn (string $title) => str_contains($title, $day->format('d/m Y')))
        ->assertJsonPath('data.embeds.0.description', fn (string $description) => str_contains($description, 'Vita rummet: Spelkväll'));
})->with(['j/n Y', 'Y-m-d']);

test('/ledigt without a date shows today', function () {
    dicPost(dicCommand('ledigt'), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.embeds.0.title', fn (string $title) => str_contains($title, now()->format('d/m Y')));
});

test('/ledigt explains when the date cannot be understood', function (string $input) {
    dicPost(dicCommand('ledigt', ['datum' => $input]), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.content', fn (string $content) => str_contains($content, 'Förstod inte datumet'));
})->with(['31/2', 'hej', '@everyone']);

test('/ledigt treats a passed day and month without a year as next year', function () {
    $this->travelTo(Carbon::parse('2026-12-30 12:00'));

    dicPost(dicCommand('ledigt', ['datum' => '2/1']), $this->secretKey)
        ->assertOk()
        ->assertJsonPath('data.embeds.0.title', fn (string $title) => str_contains($title, '02/01 2027'));
});

test('register command sends the commands to the configured server', function () {
    Http::fake();
    config([
        'services.discord.client_id' => '123',
        'services.discord.bot_token' => 'token',
    ]);
    SystemSetting::set('discord.guild_id', '456');

    $exitCode = Artisan::call('discord:register-commands');

    expect($exitCode)->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://discord.com/api/v10/applications/123/guilds/456/commands'
        && collect($request->data())->pluck('name')->all() === ['idag', 'vecka', 'ledigt']);
});

test('register command fails when discord is not configured', function () {
    Http::fake();
    config(['services.discord.bot_token' => null]);

    expect(Artisan::call('discord:register-commands'))->toBe(1);
    Http::assertNothingSent();
});
