<?php

namespace Database\Seeders;

use App\Models\Membership\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\FamilyMemberHistory;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DevDataSeeder — rich example data for manual testing.
 *
 * Creates a varied spread of members, orders, family groups,
 * membership periods, discounts, sections, and GDPR requests.
 *
 * All accounts use password: "password"
 *
 * Test accounts summary:
 *   admin@example.com            — admin
 *   kassaansvarig@example.com    — treasurer
 *   redaktor@example.com         — website-editor (already seeded)
 *   kassor@example.com           — member-manager (already seeded)
 *   medlem@example.com           — aktiv enkelt 2026 (already seeded as Maja)
 *   familjeadmin@example.com     — aktiv familj 2026, betalningsansvarig
 *   familjemedlem@example.com    — aktiv familj 2026, annat konto i samma familj
 *   student@example.com          — aktiv student 2026
 *   arlig@example.com            — äresmedlem (ingen avgift)
 *   forfall@example.com          — förfallen (ingen period 2026)
 *   inaktiv@example.com          — inaktiverad manuellt
 *   gdpr@example.com             — väntar GDPR-radering
 *   rabatt@example.com           — enkelt med rabatt 2026
 *   pengar@example.com           — pending betalning (ej betalt ännu)
 */
class DevDataSeeder extends Seeder
{
    private int $year;
    private int $prevYear;

    public function run(): void
    {
        $this->year     = now()->year;
        $this->prevYear = $this->year - 1;

        $this->seedSections();
        $this->seedSingleMembers();
        $this->seedFamilyGroup();
        $this->seedExpiredAndInactiveMembers();
        $this->seedGdprRequest();
        $this->seedDiscounts();
    }

    // ── Sections ────────────────────────────────────────────────────────────

    private function seedSections(): void
    {
        $this->command->line('  Sections…');

        $bradspel = Section::firstOrCreate(
            ['slug' => 'bradspel'],
            ['name' => 'Brädspel', 'description' => 'Sektionen för brädspelsentusiaster', 'is_active' => true]
        );
        $miniatyr = Section::firstOrCreate(
            ['slug' => 'miniatyrspel'],
            ['name' => 'Miniatyrspel', 'description' => 'Warhammer, Kings of War m.m.', 'is_active' => true]
        );
        $rpg = Section::firstOrCreate(
            ['slug' => 'rollspel'],
            ['name' => 'Rollspel', 'description' => 'Klassiska bordssittande rollspel', 'is_active' => true]
        );
        Section::firstOrCreate(
            ['slug' => 'digitalt'],
            ['name' => 'Digitala spel', 'description' => 'LAN-spel och e-sport', 'is_active' => false]
        );

        // A child section under brädspel
        Section::firstOrCreate(
            ['slug' => 'eurogames'],
            [
                'name'      => 'Eurogames',
                'parent_id' => $bradspel->id,
                'is_active' => true,
            ]
        );
    }

    // ── Single/student/honorary members ─────────────────────────────────────

    private function seedSingleMembers(): void
    {
        $this->command->line('  Enkla/student/äres-medlemmar…');

        // ── Maja Medlem — aktiv enkelt, 2 år historik + 2026 betald ──────────
        $majaUser = User::firstOrCreate(
            ['email' => 'medlem@example.com'],
            [
                'name'              => 'Maja Medlem',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $maja = Member::firstOrCreate(
            ['email' => 'medlem@example.com'],
            [
                'user_id'         => $majaUser->id,
                'first_name'      => 'Maja',
                'last_name'       => 'Medlem',
                'phone'           => '070-111 22 33',
                'address'         => 'Storgatan 12',
                'postal_code'     => '503 38',
                'city'            => 'Borås',
                'date_of_birth'   => '1990-06-15',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        // Historisk order 2024
        $this->makePaidOrder($majaUser, $maja, $this->prevYear - 1, 25000, 'Enkelmedlemskap ' . ($this->prevYear - 1));
        // Order 2025
        $this->makePaidOrder($majaUser, $maja, $this->prevYear, 25000, 'Enkelmedlemskap ' . $this->prevYear);
        // Order 2026 (aktuell)
        $this->makePaidOrder($majaUser, $maja, $this->year, 25000, 'Enkelmedlemskap ' . $this->year);

        // ── Student Sara — aktiv student, 2026 betald ─────────────────────────
        $studentUser = User::firstOrCreate(
            ['email' => 'student@example.com'],
            [
                'name'              => 'Sara Student',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $sara = Member::firstOrCreate(
            ['email' => 'student@example.com'],
            [
                'user_id'         => $studentUser->id,
                'first_name'      => 'Sara',
                'last_name'       => 'Student',
                'date_of_birth'   => '2002-03-10',
                'membership_type' => Member::TYPE_STUDENT,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        $this->makePaidOrder($studentUser, $sara, $this->year, 12500, 'Studentmedlemskap ' . $this->year);

        // ── Rabatt-Erik — enkelt med rabatt 2026 ─────────────────────────────
        $rabattUser = User::firstOrCreate(
            ['email' => 'rabatt@example.com'],
            [
                'name'              => 'Erik Rabatt',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $erik = Member::firstOrCreate(
            ['email' => 'rabatt@example.com'],
            [
                'user_id'         => $rabattUser->id,
                'first_name'      => 'Erik',
                'last_name'       => 'Rabatt',
                'date_of_birth'   => '1988-11-20',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        // Order med rabatt: 25000 - 5000 = 20000 öre
        $this->makePaidOrderWithDiscount($rabattUser, $erik, $this->year, 25000, 5000, 'Enkelmedlemskap ' . $this->year);

        // ── Pengar-Petra — pending betalning ─────────────────────────────────
        $pendingUser = User::firstOrCreate(
            ['email' => 'pengar@example.com'],
            [
                'name'              => 'Petra Pengar',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $petra = Member::firstOrCreate(
            ['email' => 'pengar@example.com'],
            [
                'user_id'         => $pendingUser->id,
                'first_name'      => 'Petra',
                'last_name'       => 'Pengar',
                'date_of_birth'   => '1995-07-04',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_PENDING,
            ]
        );
        // Paid order from last year
        $this->makePaidOrder($pendingUser, $petra, $this->prevYear, 25000, 'Enkelmedlemskap ' . $this->prevYear);
        // Pending order this year — registered but not paid
        $order = $this->makePendingOrder($pendingUser, $petra, $this->year, 25000, 'Enkelmedlemskap ' . $this->year);
        // Make the order look like it's been pending for 10 days (should trigger payment reminder)
        $order->updateQuietly(['created_at' => now()->subDays(10)]);

        // ── Äresmedlem Åke — honorary, no payment needed ─────────────────────
        $aresUser = User::firstOrCreate(
            ['email' => 'arlig@example.com'],
            [
                'name'              => 'Åke Äresborgare',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $ake = Member::firstOrCreate(
            ['email' => 'arlig@example.com'],
            [
                'user_id'         => $aresUser->id,
                'first_name'      => 'Åke',
                'last_name'       => 'Äresborgare',
                'date_of_birth'   => '1955-03-22',
                'membership_type' => Member::TYPE_HONORARY,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        // Honorary members get a free period — no payment
        MembershipPeriod::firstOrCreate(
            ['member_id' => $ake->id, 'year' => $this->year],
            [
                'membership_type' => Member::TYPE_HONORARY,
                'status'          => MembershipPeriod::STATUS_ACTIVE,
                'activated_at'    => now()->startOfYear(),
                'expires_at'      => now()->endOfYear(),
            ]
        );

        // ── Nils Nybörjare — pending, no account (already seeded) ─────────────
        // Just ensure a pending order exists for the no-account-yet scenario
        $nils = Member::firstOrCreate(
            ['email' => 'ny@example.com'],
            [
                'first_name'      => 'Nils',
                'last_name'       => 'Nybörjare',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_PENDING,
            ]
        );
    }

    // ── Family group ─────────────────────────────────────────────────────────

    private function seedFamilyGroup(): void
    {
        $this->command->line('  Familjegrupp…');

        // ── Per Familj — betalningsansvarig, adult ───────────────────────────
        $perUser = User::firstOrCreate(
            ['email' => 'familjeadmin@example.com'],
            [
                'name'              => 'Per Familj',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $per = Member::firstOrCreate(
            ['email' => 'familjeadmin@example.com'],
            [
                'user_id'         => $perUser->id,
                'first_name'      => 'Per',
                'last_name'       => 'Familj',
                'date_of_birth'   => '1985-04-12',
                'membership_type' => Member::TYPE_FAMILY,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        // ── Lisa Familj — vuxen med eget konto ───────────────────────────────
        $lisaUser = User::firstOrCreate(
            ['email' => 'familjemedlem@example.com'],
            [
                'name'              => 'Lisa Familj',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $lisa = Member::firstOrCreate(
            ['email' => 'familjemedlem@example.com'],
            [
                'user_id'         => $lisaUser->id,
                'first_name'      => 'Lisa',
                'last_name'       => 'Familj',
                'date_of_birth'   => '1987-08-30',
                'membership_type' => Member::TYPE_FAMILY,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        // ── Lova Familj — barn, ingen inloggning ─────────────────────────────
        $lova = Member::firstOrCreate(
            ['email' => null, 'first_name' => 'Lova', 'last_name' => 'Familj'],
            [
                'user_id'         => null,
                'first_name'      => 'Lova',
                'last_name'       => 'Familj',
                'date_of_birth'   => '2012-02-14',
                'membership_type' => Member::TYPE_FAMILY,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        // Skip if family group already exists for Per
        if ($per->family_group_id) {
            return;
        }

        // Create family group — founded Jan 2025
        $group   = FamilyGroup::create(['administrator_member_id' => $per->id]);
        $founded = now()->subYear()->startOfYear();

        foreach ([$per, $lisa, $lova] as $m) {
            $isChild = $m->date_of_birth && $m->date_of_birth->gt(now()->subYears(18));
            $m->update(['family_group_id' => $group->id]);
            DB::table('family_members')->insertOrIgnore([
                'family_group_id' => $group->id,
                'member_id'       => $m->id,
                'role'            => $isChild ? FamilyGroup::ROLE_CHILD : FamilyGroup::ROLE_ADULT,
                'has_own_account' => $m->user_id !== null ? 1 : 0,
            ]);
        }

        // ── En familjeorder 2025 (betald, 2 vuxna + 1 barn) ─────────────────
        $prevOrder = $this->createOrder($perUser, 50000, Order::STATUS_PAID, Order::METHOD_BANKGIRO, $founded);
        // Per + Lisa = 2 vuxna
        OrderLine::create([
            'order_id'       => $prevOrder->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'slot_role'      => FamilyGroup::ROLE_ADULT,
            'description'    => 'Familjemedlemskap vuxen ' . $this->prevYear,
            'quantity'       => 2,
            'unit_price_ore' => 20000,
            'discount_ore'   => 0,
            'line_total_ore' => 40000,
        ]);
        // Lova = 1 barn
        OrderLine::create([
            'order_id'       => $prevOrder->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'slot_role'      => FamilyGroup::ROLE_CHILD,
            'description'    => 'Familjemedlemskap barn ' . $this->prevYear,
            'quantity'       => 1,
            'unit_price_ore' => 10000,
            'discount_ore'   => 0,
            'line_total_ore' => 10000,
        ]);
        foreach ([$per, $lisa, $lova] as $m) {
            MembershipPeriod::firstOrCreate(
                ['member_id' => $m->id, 'year' => $this->prevYear],
                [
                    'order_id'        => $prevOrder->id,
                    'membership_type' => Member::TYPE_FAMILY,
                    'status'          => MembershipPeriod::STATUS_ACTIVE,
                    'activated_at'    => $founded,
                    'expires_at'      => $founded->copy()->endOfYear(),
                ]
            );
        }

        // ── En familjeorder 2026 (betald, 2 vuxna + 2 barn-platser — 1 ledig) ─
        // Per + Lisa = 2 vuxna (alla tilldelade).
        // Lova = 1 barn (tilldelad), men 1 extra barn-plats köptes — visas i UI:t.
        $currOrderStart = now()->startOfYear()->addDays(3);
        $currOrder      = $this->createOrder($perUser, 70000, Order::STATUS_PAID, Order::METHOD_STRIPE, $currOrderStart);
        // 2 vuxna
        OrderLine::create([
            'order_id'       => $currOrder->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'slot_role'      => FamilyGroup::ROLE_ADULT,
            'description'    => 'Familjemedlemskap vuxen ' . $this->year,
            'quantity'       => 2,
            'unit_price_ore' => 20000,
            'discount_ore'   => 0,
            'line_total_ore' => 40000,
        ]);
        // 2 barn-platser (bara 1 tilldelad — 1 ledig för demo)
        OrderLine::create([
            'order_id'       => $currOrder->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'slot_role'      => FamilyGroup::ROLE_CHILD,
            'description'    => 'Familjemedlemskap barn ' . $this->year,
            'quantity'       => 2,
            'unit_price_ore' => 15000,
            'discount_ore'   => 0,
            'line_total_ore' => 30000,
        ]);
        foreach ([$per, $lisa, $lova] as $m) {
            MembershipPeriod::firstOrCreate(
                ['member_id' => $m->id, 'year' => $this->year],
                [
                    'order_id'        => $currOrder->id,
                    'membership_type' => Member::TYPE_FAMILY,
                    'status'          => MembershipPeriod::STATUS_ACTIVE,
                    'activated_at'    => $currOrderStart,
                    'expires_at'      => $currOrderStart->copy()->endOfYear(),
                ]
            );
        }

        // ── Historikposter ────────────────────────────────────────────────────
        // 1. Alla tre grundare gick med vid start (Jan 2025)
        foreach ([$per, $lisa, $lova] as $m) {
            FamilyMemberHistory::create([
                'family_group_id'      => $group->id,
                'member_id'            => $m->id,
                'action'               => FamilyMemberHistory::ACTION_JOINED,
                'performed_by_user_id' => $perUser->id,
                'year'                 => $this->prevYear,
                'created_at'           => $founded,
            ]);
        }

        // 2. Karl Kandidat — lades till av Per i mars 2025, togs sedan bort i juni
        //    (Karl är borttagen ur familjen men historiken finns kvar)
        $karl = Member::firstOrCreate(
            ['email' => 'karl.ex@example.com'],
            [
                'first_name'      => 'Karl',
                'last_name'       => 'Kandidat',
                'date_of_birth'   => '1989-07-19',
                'membership_type' => Member::TYPE_FAMILY,
                'status'          => Member::STATUS_ACTIVE,
                // Inte längre i familjen — family_group_id = null
            ]
        );
        FamilyMemberHistory::create([
            'family_group_id'      => $group->id,
            'member_id'            => $karl->id,
            'action'               => FamilyMemberHistory::ACTION_ADDED,
            'performed_by_user_id' => $perUser->id,
            'year'                 => $this->prevYear,
            'notes'                => 'Tillfällig gäst under spelkonventet.',
            'created_at'           => $founded->copy()->addMonths(2),
        ]);
        FamilyMemberHistory::create([
            'family_group_id'      => $group->id,
            'member_id'            => $karl->id,
            'action'               => FamilyMemberHistory::ACTION_REMOVED,
            'performed_by_user_id' => $perUser->id,
            'year'                 => $this->prevYear,
            'notes'                => 'Lämnade föreningen — tas bort ur familj.',
            'created_at'           => $founded->copy()->addMonths(5),
        ]);

        // 3. Lisa lämnade familjen frivilligt i aug 2025, men Per lade till henne igen i sep
        FamilyMemberHistory::create([
            'family_group_id'      => $group->id,
            'member_id'            => $lisa->id,
            'action'               => FamilyMemberHistory::ACTION_LEFT,
            'performed_by_user_id' => $lisaUser->id,
            'year'                 => $this->prevYear,
            'created_at'           => $founded->copy()->addMonths(7),
        ]);
        FamilyMemberHistory::create([
            'family_group_id'      => $group->id,
            'member_id'            => $lisa->id,
            'action'               => FamilyMemberHistory::ACTION_ADDED,
            'performed_by_user_id' => $perUser->id,
            'year'                 => $this->prevYear,
            'notes'                => 'Välkommen tillbaka!',
            'created_at'           => $founded->copy()->addMonths(8),
        ]);
    }

    // ── Expired / inactive members ───────────────────────────────────────────

    private function seedExpiredAndInactiveMembers(): void
    {
        $this->command->line('  Förfallna/inaktiva medlemmar…');

        // ── Fredrik Förfallen — hade 2025 men ingen 2026 (grace expired) ─────
        $forfallenUser = User::firstOrCreate(
            ['email' => 'forfall@example.com'],
            [
                'name'              => 'Fredrik Förfallen',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $fredrik = Member::firstOrCreate(
            ['email' => 'forfall@example.com'],
            [
                'user_id'         => $forfallenUser->id,
                'first_name'      => 'Fredrik',
                'last_name'       => 'Förfallen',
                'date_of_birth'   => '1978-12-01',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_EXPIRED,
            ]
        );
        $this->makePaidOrder($forfallenUser, $fredrik, $this->prevYear, 25000, 'Enkelmedlemskap ' . $this->prevYear);
        AuditLog::firstOrCreate(
            [
                'action'         => 'membership_deactivated',
                'auditable_type' => Member::class,
                'auditable_id'   => $fredrik->id,
            ],
            [
                'area'    => 'members',
                'subject' => "Medlemskap avaktiverat automatiskt: {$fredrik->full_name}",
                'changes' => [
                    'reason'          => 'grace_period_expired',
                    'reason_label'    => 'Nådeperioden har löpt ut',
                    'previous_status' => Member::STATUS_ACTIVE,
                    'new_status'      => Member::STATUS_EXPIRED,
                    'year'            => $this->year,
                    'had_last_year'   => true,
                ],
            ]
        );

        // ── Ivan Inaktiv — manuellt inaktiverad ───────────────────────────────
        $inaktivUser = User::firstOrCreate(
            ['email' => 'inaktiv@example.com'],
            [
                'name'              => 'Ivan Inaktiv',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        Member::firstOrCreate(
            ['email' => 'inaktiv@example.com'],
            [
                'user_id'         => $inaktivUser->id,
                'first_name'      => 'Ivan',
                'last_name'       => 'Inaktiv',
                'date_of_birth'   => '1982-05-17',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_INACTIVE,
            ]
        );

        // ── Gunnar NoYear — aktiv men saknar period helt (inget historik) ─────
        $noyearUser = User::firstOrCreate(
            ['email' => 'noyear@example.com'],
            [
                'name'              => 'Gunnar Noperiod',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        Member::firstOrCreate(
            ['email' => 'noyear@example.com'],
            [
                'user_id'         => $noyearUser->id,
                'first_name'      => 'Gunnar',
                'last_name'       => 'Noperiod',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_EXPIRED,
            ]
        );
        AuditLog::firstOrCreate(
            [
                'action'         => 'membership_deactivated',
                'auditable_type' => Member::class,
                'auditable_id'   => $noyearUser->id,
            ],
            [
                'area'    => 'members',
                'subject' => 'Medlemskap avaktiverat automatiskt: Gunnar Noperiod',
                'changes' => [
                    'reason'          => 'no_membership_period',
                    'reason_label'    => 'Saknar aktiv period för innevarande år',
                    'previous_status' => Member::STATUS_ACTIVE,
                    'new_status'      => Member::STATUS_EXPIRED,
                    'year'            => $this->year,
                    'had_last_year'   => false,
                ],
            ]
        );
    }

    // ── GDPR deletion requests ───────────────────────────────────────────────

    private function seedGdprRequest(): void
    {
        $this->command->line('  GDPR-raderingsförfrågningar…');

        // ── Pending request ───────────────────────────────────────────────────
        $gdprUser = User::firstOrCreate(
            ['email' => 'gdpr@example.com'],
            [
                'name'              => 'Gunnar GDPR',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $gunnar = Member::firstOrCreate(
            ['email' => 'gdpr@example.com'],
            [
                'user_id'         => $gdprUser->id,
                'first_name'      => 'Gunnar',
                'last_name'       => 'GDPR',
                'date_of_birth'   => '1975-09-08',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        $this->makePaidOrder($gdprUser, $gunnar, $this->year, 25000, 'Enkelmedlemskap ' . $this->year);

        AccountDeletionRequest::firstOrCreate(
            ['member_id' => $gunnar->id, 'status' => AccountDeletionRequest::STATUS_PENDING],
            [
                'user_id'   => $gdprUser->id,
                'reason'    => 'Jag vill inte längre vara med i föreningen och vill att mina uppgifter raderas.',
                'created_at' => now()->subDays(2),
            ]
        );

        // ── Rejected request (historical) ─────────────────────────────────────
        $rejectedUser = User::firstOrCreate(
            ['email' => 'nekad@example.com'],
            [
                'name'              => 'Nina Nekad',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $nina = Member::firstOrCreate(
            ['email' => 'nekad@example.com'],
            [
                'user_id'         => $rejectedUser->id,
                'first_name'      => 'Nina',
                'last_name'       => 'Nekad',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );
        $this->makePaidOrder($rejectedUser, $nina, $this->year, 25000, 'Enkelmedlemskap ' . $this->year);

        $adminUser = User::where('email', 'kassor@example.com')->first()
            ?? User::where('email', 'orjan.almen@gmail.com')->first();

        AccountDeletionRequest::firstOrCreate(
            ['member_id' => $nina->id, 'status' => AccountDeletionRequest::STATUS_REJECTED],
            [
                'user_id'     => $rejectedUser->id,
                'reason'      => 'Vill sluta.',
                'admin_note'  => 'Du har aktiva hyresfordringar kvar. Kontakta kassan först.',
                'actioned_by' => $adminUser?->id,
                'actioned_at' => now()->subDay(),
                'created_at'  => now()->subDays(3),
            ]
        );
    }

    // ── Discounts ────────────────────────────────────────────────────────────

    private function seedDiscounts(): void
    {
        if (! class_exists(Discount::class)) {
            return;
        }

        $this->command->line('  Rabatter…');

        // 20 % förstamedlemsrabatt (automatisk, inget kod)
        $firstMemberDiscount = Discount::firstOrCreate(
            ['name' => 'Förstamemblemsdiscount 20%'],
            [
                'type'        => 'percentage',
                'value'       => 20,
                'applies_to'  => 'membership',
                'condition'   => 'first_membership',
                'is_active'   => true,
                'valid_from'  => now()->startOfYear(),
                'valid_until' => now()->endOfYear(),
            ]
        );

        // 50 kr fast rabatt via promo-kod
        $promoDiscount = Discount::firstOrCreate(
            ['name' => 'Promo 50 kr'],
            [
                'type'       => 'fixed',
                'value'      => 5000,
                'applies_to' => 'all',
                'is_active'  => true,
                'max_uses'   => 100,
            ]
        );

        DiscountCode::firstOrCreate(
            ['code' => 'VÄLKOMMEN50'],
            [
                'discount_id'   => $promoDiscount->id,
                'is_single_use' => false,
            ]
        );

        // Enstaka-bruk vinnarrabatt
        $prizeDiscount = Discount::firstOrCreate(
            ['name' => 'Turneringsvinnare 100% off'],
            [
                'type'       => 'percentage',
                'value'      => 100,
                'applies_to' => 'membership',
                'is_active'  => true,
                'max_uses'   => 5,
            ]
        );

        // Tre unika engångskoder
        foreach (['VINNARE-2026-A', 'VINNARE-2026-B', 'VINNARE-2026-C'] as $code) {
            DiscountCode::firstOrCreate(
                ['code' => $code],
                [
                    'discount_id'   => $prizeDiscount->id,
                    'is_single_use' => true,
                ]
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makePaidOrder(User $user, Member $member, int $year, int $amountOre, string $description): Order
    {
        $paidAt   = $year === now()->year
            ? now()->startOfYear()->addDays(rand(1, 30))
            : now()->subYears(now()->year - $year)->startOfYear()->addDays(rand(1, 30));

        $order = $this->createOrder($user, $amountOre, Order::STATUS_PAID, Order::METHOD_BANKGIRO, $paidAt->subDays(5), $paidAt);

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'description'    => $description,
            'quantity'       => 1,
            'unit_price_ore' => $amountOre,
            'discount_ore'   => 0,
            'line_total_ore' => $amountOre,
        ]);

        MembershipPeriod::firstOrCreate(
            ['member_id' => $member->id, 'year' => $year],
            [
                'order_id'        => $order->id,
                'membership_type' => $member->membership_type,
                'status'          => MembershipPeriod::STATUS_ACTIVE,
                'activated_at'    => $paidAt,
                'expires_at'      => (clone $paidAt)->endOfYear(),
            ]
        );

        return $order;
    }

    private function makePaidOrderWithDiscount(User $user, Member $member, int $year, int $unitPriceOre, int $discountOre, string $description): Order
    {
        $lineTotal = $unitPriceOre - $discountOre;
        $paidAt    = now()->startOfYear()->addDays(rand(2, 20));

        $order = $this->createOrder($user, $lineTotal, Order::STATUS_PAID, Order::METHOD_STRIPE, $paidAt->subDays(1), $paidAt);

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'description'    => $description,
            'quantity'       => 1,
            'unit_price_ore' => $unitPriceOre,
            'discount_ore'   => $discountOre,
            'line_total_ore' => $lineTotal,
        ]);

        MembershipPeriod::firstOrCreate(
            ['member_id' => $member->id, 'year' => $year],
            [
                'order_id'        => $order->id,
                'membership_type' => $member->membership_type,
                'status'          => MembershipPeriod::STATUS_ACTIVE,
                'activated_at'    => $paidAt,
                'expires_at'      => (clone $paidAt)->endOfYear(),
            ]
        );

        return $order;
    }

    private function makePendingOrder(User $user, Member $member, int $year, int $amountOre, string $description): Order
    {
        $order = $this->createOrder($user, $amountOre, Order::STATUS_PENDING, null, now()->subDays(10));

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'description'    => $description,
            'quantity'       => 1,
            'unit_price_ore' => $amountOre,
            'discount_ore'   => 0,
            'line_total_ore' => $amountOre,
        ]);

        MembershipPeriod::firstOrCreate(
            ['member_id' => $member->id, 'year' => $year],
            [
                'order_id'        => $order->id,
                'membership_type' => $member->membership_type,
                'status'          => MembershipPeriod::STATUS_PENDING_PAYMENT,
            ]
        );

        return $order;
    }

    private function createOrder(User $user, int $totalOre, string $status, ?string $method, \Carbon\Carbon $createdAt, ?\Carbon\Carbon $paidAt = null): Order
    {
        $order = Order::create([
            'user_id'        => $user->id,
            'status'         => $status,
            'total_ore'      => $totalOre,
            'payment_method' => $method,
            'paid_at'        => $paidAt,
        ]);

        // Backdate created_at (model sets it on create, so we must override)
        $order->updateQuietly(['created_at' => $createdAt]);

        return $order;
    }
}
