<?php

namespace App\Console\Commands;

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\User;
use App\Services\CogWorksService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCogWorks extends Command
{
    private array $feeCache = [];

    protected $signature = 'import:cogworks
                            {--dry-run : Visa vad som skulle göras utan att spara}
                            {--skip-avatars : Hoppa över nedladdning av profilbilder}
                            {--save-cache : Spara API-svaret till database/fixtures/cogworks.json}
                            {--from-cache : Läs från database/fixtures/cogworks.json istället för API}
                            {--from-year= : Hämta medlemmar aktiva fr.o.m. detta år (standard: 1 år bakåt)}';

    private const CACHE_PATH = 'database/fixtures/cogworks.json';

    protected $description = 'Importera/synkronisera användare från CogWorks (Mina Aktiviteter)';

    public function handle(CogWorksService $cogworks): int
    {
        $dryRun      = $this->option('dry-run');
        $skipAvatars = $this->option('skip-avatars');

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar sparas.');
        }

        if ($this->option('from-cache')) {
            $users = $this->loadFromCache();
            if ($users === null) {
                $this->error('Cache saknas: ' . self::CACHE_PATH . '. Kör utan --from-cache för att hämta från API.');
                return self::FAILURE;
            }
            $this->info('Läser ' . \count($users) . ' användare från lokal cache (' . self::CACHE_PATH . ')…');
        } else {
            $fromYear    = $this->option('from-year');
            $minMshipDate = $fromYear ? "{$fromYear}-01-01" : null;
            $this->info('Hämtar användare från CogWorks' . ($minMshipDate ? " (fr.o.m. {$minMshipDate})" : '') . '…');
            $users = $cogworks->getAllUsers($minMshipDate);

            if ($users === null) {
                $this->error('Kunde inte hämta användare. Kontrollera COGWORKS_ORG_CODE och COGWORKS_API_TOKEN.');
                return self::FAILURE;
            }

            if ($this->option('save-cache')) {
                $this->saveCache($users);
                $this->info('Cache sparad till ' . self::CACHE_PATH . '.');
            }
        }

        if (empty($users)) {
            $this->warn('Inga användare returnerades från CogWorks.');
            return self::SUCCESS;
        }

        $this->info(\count($users) . ' personer hittades i CogWorks.');

        // Fast path: bulk inserts when seeding into an empty database (migrate:fresh + from-cache).
        // Avoids 435 individual bcrypt calls (~90s) and hundreds of per-row queries.
        if ($this->option('from-cache') && ! $dryRun && User::count() === 0) {
            return $this->bulkInsert($users);
        }

        // Index existing users by cogworks_id for fast lookups
        /** @var \Illuminate\Support\Collection<string, User> $usersByCogworksId */
        $usersByCogworksId = User::whereNotNull('cogworks_id')
            ->get()
            ->keyBy('cogworks_id');

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $avatars  = 0;
        $periods  = 0;

        foreach ($users as $data) {
            if (empty($data['user_id'])) {
                $skipped++;
                continue;
            }

            $cogworksId = $data['user_id'];
            $firstName  = trim($data['first_name']);
            $lastName   = trim($data['last_name']);
            $name       = trim("{$firstName} {$lastName}") ?: ($data['username'] ?: $cogworksId);
            $email      = $data['email'] ?: null;
            $username   = $data['username'] ?: null;

            // Try to find existing user by cogworks_id, then by email.
            // Email fallback only applies to native accounts that haven't been linked yet
            // (cogworks_id IS NULL) — never overwrite a different person's cogworks_id.
            // If the email is already taken by another CogWorks user, we still import
            // the Member record but leave user_id = null (no login access until admin sorts it out).
            $user          = $usersByCogworksId->get($cogworksId);
            $emailConflict = false;

            if (! $user && $email) {
                $candidate = User::where('email', $email)->first();
                if ($candidate && $candidate->cogworks_id === null) {
                    $user = $candidate;
                } elseif ($candidate && $candidate->cogworks_id !== $cogworksId) {
                    $this->warn("  Delad e-post: {$name} ({$email}) — importeras som medlem utan användarkonto.");
                    $emailConflict = true;
                }
            }

            $userFields = [
                'cogworks_id'       => $cogworksId,
                'cogworks_username' => $username,
                'name'              => $name,
            ];

            if ($email) {
                $userFields['email'] = $email;
            }

            if ($user) {
                if (! $dryRun) {
                    $user->update($userFields);
                }
                $updated++;
            } elseif (! $emailConflict) {
                if (! $email) {
                    $this->warn("  Hoppar över {$name} — ingen e-post och inget befintligt konto.");
                    $skipped++;
                    continue;
                }

                if (! $dryRun) {
                    $user = User::create([
                        ...$userFields,
                        'password'   => Hash::make(Str::random(32)),
                        'ical_token' => Str::random(48),
                    ]);
                    $usersByCogworksId->put($cogworksId, $user);
                }

                $this->line("  <fg=green>Skapade:</> {$name} ({$email})");
                $created++;
            }

            if (! $user && ! $emailConflict) {
                continue; // dry-run, no user object
            }

            // ── Member record ──────────────────────────────────────────────────

            $memberFields = [
                'cogworks_id'       => $cogworksId,
                'membership_number' => $data['membership_number'] ?: null,
                'first_name'        => $firstName ?: Str::before($name, ' '),
                'last_name'         => $lastName  ?: Str::after($name, ' '),
                'email'             => $email,
                'phone'             => $data['phone'] ?: null,
                'address'           => $data['address'] ?: null,
                'postal_code'       => $data['postal_code'] ?: null,
                'city'              => $data['city'] ?: null,
                'date_of_birth'     => $data['date_of_birth'] ?: null,
            ];

            // Always look up member by cogworks_id — never via user->member,
            // because multiple members can share the same email/user_id (family accounts).
            $member = Member::where('cogworks_id', $cogworksId)->first();

            if ($member) {
                if (! $dryRun) {
                    $member->update($memberFields + ['membership_type' => $this->resolveMembershipType($data)]);
                }
            } else {
                $memberFields = [
                    ...$memberFields,
                    'user_id'         => $user?->id,  // null for shared-email / no-account members
                    'membership_type' => $this->resolveMembershipType($data),
                    'status'          => $data['is_member'] ? Member::STATUS_ACTIVE : Member::STATUS_INACTIVE,
                ];

                if (! $dryRun) {
                    $member = Member::create($memberFields);
                    $this->line("    → Skapade medlemspost (#{$member->membership_number})");
                }
            }

            // ── Membership period from lastMembership ──────────────────────────

            if ($member && $data['last_period_year'] && ! $dryRun) {
                $year = (int) $data['last_period_year'];

                $existingPeriod = $member->membershipPeriods()->where('year', $year)->first();

                if (! $existingPeriod) {
                    $member->membershipPeriods()->create([
                        'year'            => $year,
                        'membership_type' => $member->membership_type,
                        'status'          => $data['is_member'] ? MembershipPeriod::STATUS_ACTIVE : MembershipPeriod::STATUS_EXPIRED,
                        'activated_at'    => $data['last_period_start'] ?: null,
                        'expires_at'      => $data['last_period_end'] ?: null,
                    ]);
                    $periods++;
                }
            }

            // ── Avatar from CogWorks thumb ─────────────────────────────────────

            if (! $skipAvatars && $data['thumb_url'] && $user && ! $user->avatar_path && ! $dryRun) {
                try {
                    $response = Http::timeout(10)->get($data['thumb_url']);

                    if ($response->successful()) {
                        $filename = 'avatars/' . Str::random(40) . '.jpg';
                        Storage::disk('public')->put($filename, $response->body());
                        $user->update(['avatar_path' => $filename]);
                        $avatars++;
                    }
                } catch (\Exception $e) {
                    // Non-fatal — skip avatar silently
                }
            }
        }

        $this->newLine();
        $this->info("Klart. Skapade: {$created}, uppdaterade: {$updated}, hoppade över: {$skipped}.");
        $this->info("Perioder skapade: {$periods}, avatarer hämtade: {$avatars}.");

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar sparades.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve membership type using age thresholds from membership_fees for the
     * import year, falling back to CogWorks type code if DOB or fees are missing.
     *
     * CogWorks codes: N=Normal, FP=Hel familj, F=Familjemedlem, U=Ungdom
     */
    private function resolveMembershipType(array $data): string
    {
        $cogType = strtoupper(trim($data['last_period_type'] ?? ''));
        $dob     = $data['date_of_birth'] ?: null;

        // Family types can't be derived from age alone — trust CogWorks directly.
        if ($cogType === 'FP') {
            return Member::TYPE_FAMILY;
        }
        if ($cogType === 'F') {
            // Family member — use age to distinguish child vs adult family member.
            if ($dob) {
                $age  = (int) now()->diffInYears(\Carbon\Carbon::parse($dob));
                $year = (int) ($data['last_period_year'] ?: now()->year);
                $fees = $this->feesForYear($year);

                $childFee = $fees->get(Member::TYPE_CHILD);
                if ($childFee && $childFee->max_age !== null && $age <= $childFee->max_age) {
                    return Member::TYPE_CHILD;
                }
            }
            return Member::TYPE_FAMILY;
        }

        // For N and U: if we have DOB, derive type from membership_fees age ranges.
        if ($dob) {
            $age  = (int) now()->diffInYears(\Carbon\Carbon::parse($dob));
            $year = (int) ($data['last_period_year'] ?: now()->year);
            $fees = $this->feesForYear($year)->filter(fn ($f) => $f->is_active);

            // Walk active fees ordered by sort_order, find the first whose age range matches.
            foreach ($fees as $type => $fee) {
                if (\in_array($type, [Member::TYPE_FAMILY, Member::TYPE_HONORARY], true)) {
                    continue;
                }
                $withinMin = $fee->min_age === null || $age >= $fee->min_age;
                $withinMax = $fee->max_age === null || $age <= $fee->max_age;
                if ($withinMin && $withinMax) {
                    return $type;
                }
            }
        }

        // Fallback: map CogWorks code directly.
        return match ($cogType) {
            'U'     => Member::TYPE_YOUTH,
            default => Member::TYPE_SINGLE,
        };
    }

    private function feesForYear(int $year): \Illuminate\Support\Collection
    {
        return $this->feeCache[$year] ??= \App\Models\Membership\MembershipFee::forYear($year);
    }

    private function bulkInsert(array $users): int
    {
        $now         = now();
        $placeholder = Hash::make(Str::random(32));

        $userRows   = [];
        $memberMeta = []; // cogworks_id => [type, is_member, period data]

        foreach ($users as $data) {
            if (empty($data['user_id']) || empty($data['email'])) {
                continue;
            }

            $cogworksId = $data['user_id'];
            $firstName  = trim($data['first_name']);
            $lastName   = trim($data['last_name']);
            $name       = trim("{$firstName} {$lastName}") ?: ($data['username'] ?: $cogworksId);

            $userRows[$cogworksId] = [
                'cogworks_id'       => $cogworksId,
                'cogworks_username' => $data['username'] ?: null,
                'name'              => $name,
                'email'             => $data['email'],
                'password'          => $placeholder,
                'ical_token'        => Str::random(48),
                'created_at'        => $now,
                'updated_at'        => $now,
            ];

            $memberMeta[$cogworksId] = [
                'first_name'      => $firstName ?: Str::before($name, ' '),
                'last_name'       => $lastName  ?: Str::after($name, ' '),
                'email'           => $data['email'],
                'phone'           => $data['phone'] ?: null,
                'address'         => $data['address'] ?: null,
                'postal_code'     => $data['postal_code'] ?: null,
                'city'            => $data['city'] ?: null,
                'date_of_birth'   => $data['date_of_birth'] ?: null,
                'membership_type' => $this->resolveMembershipType($data),
                'membership_number' => $data['membership_number'] ?: null,
                'status'          => $data['is_member'] ? Member::STATUS_ACTIVE : Member::STATUS_INACTIVE,
                'period_year'     => $data['last_period_year'] ? (int) $data['last_period_year'] : null,
                'period_start'    => $data['last_period_start'] ?: null,
                'period_end'      => $data['last_period_end'] ?: null,
                'is_member'       => (bool) $data['is_member'],
            ];
        }

        // Deduplicate by email — keep last occurrence (same logic as CogWorks API order)
        $uniqueByEmail = [];
        foreach ($userRows as $cogworksId => $row) {
            $uniqueByEmail[$row['email']] = $cogworksId;
        }
        $userRows = array_filter($userRows, fn ($row) => $uniqueByEmail[$row['email']] === $row['cogworks_id']);

        foreach (array_chunk(array_values($userRows), 100) as $chunk) {
            User::insert($chunk);
        }

        $userIdsByCogworks = User::whereIn('cogworks_id', array_keys($memberMeta))
            ->pluck('id', 'cogworks_id');

        $memberRows = [];
        foreach ($memberMeta as $cogworksId => $meta) {
            if (! isset($userRows[$cogworksId])) {
                continue; // was deduplicated out
            }
            $memberRows[$cogworksId] = [
                'cogworks_id'       => $cogworksId,
                'user_id'           => $userIdsByCogworks->get($cogworksId),
                'membership_number' => $meta['membership_number'],
                'first_name'        => $meta['first_name'],
                'last_name'         => $meta['last_name'],
                'email'             => $meta['email'],
                'phone'             => $meta['phone'],
                'address'           => $meta['address'],
                'postal_code'       => $meta['postal_code'],
                'city'              => $meta['city'],
                'date_of_birth'     => $meta['date_of_birth'],
                'membership_type'   => $meta['membership_type'],
                'status'            => $meta['status'],
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        foreach (array_chunk(array_values($memberRows), 100) as $chunk) {
            Member::insert($chunk);
        }

        $memberIdsByCogworks = Member::whereIn('cogworks_id', array_keys($memberRows))
            ->pluck('id', 'cogworks_id');

        $periodRows = [];
        foreach ($memberMeta as $cogworksId => $meta) {
            if (! isset($memberRows[$cogworksId]) || ! $meta['period_year']) {
                continue;
            }
            $periodRows[] = [
                'member_id'       => $memberIdsByCogworks->get($cogworksId),
                'year'            => $meta['period_year'],
                'membership_type' => $meta['membership_type'],
                'status'          => $meta['is_member'] ? MembershipPeriod::STATUS_ACTIVE : MembershipPeriod::STATUS_EXPIRED,
                'activated_at'    => $meta['period_start'],
                'expires_at'      => $meta['period_end'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        foreach (array_chunk($periodRows, 100) as $chunk) {
            MembershipPeriod::insert($chunk);
        }

        $created = count($memberRows);
        $periods = count($periodRows);
        $this->info("Bulk-import klar. Skapade: {$created} användare/medlemmar, {$periods} perioder.");

        return self::SUCCESS;
    }

    private function loadFromCache(): ?array
    {
        if (! file_exists(base_path(self::CACHE_PATH))) {
            return null;
        }

        $json = file_get_contents(base_path(self::CACHE_PATH));
        $data = json_decode($json, true);

        return \is_array($data) ? $data : null;
    }

    private function saveCache(array $users): void
    {
        $dir = dirname(base_path(self::CACHE_PATH));
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            base_path(self::CACHE_PATH),
            json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
