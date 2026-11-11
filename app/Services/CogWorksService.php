<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CogWorksService
{
    private string $orgCode;
    private string $apiToken;

    public function __construct()
    {
        $this->orgCode  = config('services.cogworks.org_code', '');
        $this->apiToken = config('services.cogworks.api_token', '');
    }

    public function isConfigured(): bool
    {
        return $this->orgCode !== '' && $this->apiToken !== '';
    }

    /**
     * Authenticate a user against CogWorks.
     *
     * Returns ['id', 'name', 'email', 'username'] on success,
     * or ['error' => string] on failure,
     * or null if CogWorks is not configured / unreachable.
     */
    public function authenticate(string $username, string $password): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(8)->asForm()->post('https://minaaktiviteter.se/xml/', [
                'type'     => 'userLogin',
                'org'      => $this->orgCode,
                'pw'       => $this->apiToken,
                'username' => $username,
                'userPw'   => $password,
            ]);

            if (! $response->successful()) {
                Log::warning('CogWorks auth HTTP error', ['status' => $response->status(), 'username' => $username]);

                return null; // treat as unavailable, not wrong password
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                Log::error('CogWorks XML parse error', ['username' => $username]);

                return null;
            }

            $login = $xml->userLogin ?? null;

            if (! $login || (string) ($login->userId ?? '0') === '0') {
                // Explicit credential failure from CogWorks
                return ['error' => 'Felaktigt användarnamn eller lösenord i CogWorks.'];
            }

            return [
                'id'       => (string) $login->userId,
                'name'     => (string) ($login->name ?? ''),
                'email'    => (string) ($login->email ?? ''),
                'username' => $username,
            ];
        } catch (\Exception $e) {
            Log::warning('CogWorks auth exception — treating as unavailable', ['message' => $e->getMessage()]);

            return null; // network error → fall through to local auth
        }
    }

    /**
     * Look up a single CogWorks user by email address.
     * Returns the user array (same shape as getAllUsers entries) or null if not found.
     */
    public function findByEmail(string $email): ?array
    {
        $users = $this->getAllUsers();

        if ($users === null) {
            return null;
        }

        return collect($users)->first(
            fn ($u) => strtolower($u['email']) === strtolower($email)
        );
    }

    /**
     * Fetch all users with group memberships for bulk import.
     */
    public function getAllUsers(?string $minMshipDate = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->asForm()->post('https://minaaktiviteter.se/xml/', [
                'type'                   => 'users',
                'org'                    => $this->orgCode,
                'pw'                     => $this->apiToken,
                'minMshipDate'           => $minMshipDate ?? now()->subYear()->format('Y-m-d'),
                'show[username]'         => 1,
                'show[email]'            => 1,
                'show[phone]'            => 1,
                'show[address]'          => 1,
                'show[dateOfBirth]'      => 1,
                'show[mshipNumber]'      => 1,
                'show[isMember]'         => 1,
                'show[lastMembership]'   => 1,
                'show[thumb]'            => 1,
                'show[userGroupMships]'  => 1,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false || isset($xml->error)) {
                return null;
            }

            $groupNames = [];
            foreach ($xml->groups->group ?? [] as $group) {
                $groupNames[(string) $group['id']] = (string) $group->name;
            }

            $users = [];
            foreach ($xml->persons->person ?? [] as $person) {
                $userGroups = [];
                foreach ($person->groupId as $gid) {
                    $id = (string) $gid;
                    if (isset($groupNames[$id])) {
                        $userGroups[] = $groupNames[$id];
                    }
                }

                // last membership period
                $lastMship = $person->lastMembership ?? null;

                $users[] = [
                    'user_id'           => (string) ($person->userId ?? ''),
                    'first_name'        => (string) ($person->firstName ?? ''),
                    'last_name'         => (string) ($person->lastName ?? ''),
                    'email'             => (string) ($person->email[0] ?? ''),
                    'username'          => (string) ($person->username ?? ''),
                    'phone'             => (string) ($person->phone ?? ''),
                    'address'           => (string) ($person->streetAddr ?? ''),
                    'postal_code'       => (string) ($person->zip ?? ''),
                    'city'              => (string) ($person->city ?? ''),
                    'date_of_birth'     => (string) ($person->dateOfBirth ?? ''),
                    'membership_number' => (string) ($person->mshipNumber ?? ''),
                    'is_member'         => (string) ($person->isMember ?? '0') === '1',
                    'last_period_year'  => $lastMship ? (string) ($lastMship->periodName ?? '') : '',
                    'last_period_start' => $lastMship ? (string) ($lastMship->startDate ?? '') : '',
                    'last_period_end'   => $lastMship ? (string) ($lastMship->endDate ?? '') : '',
                    'last_period_type'  => $lastMship ? (string) ($lastMship['type'] ?? '') : '',
                    'thumb_url'         => (string) ($person->thumb ?? ''),
                    'groups'            => $userGroups,
                ];
            }

            return $users;
        } catch (\Exception $e) {
            Log::error('CogWorks getAllUsers error', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
