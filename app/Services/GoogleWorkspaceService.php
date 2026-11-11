<?php

namespace App\Services;

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\WorkspaceAccount;
use App\Models\WorkspaceDelegate;
use App\Models\WorkspaceDriveMember;
use App\Models\WorkspaceSharedDrive;
use Google\Client;
use Google\Service\Directory;
use Google\Service\Directory\User as GoogleUser;
use Google\Service\Directory\UserName;
use Google\Service\Drive;
use Google\Service\Gmail;
use Google\Service\Reports;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleWorkspaceService
{
    private ?Directory $directory = null;
    private ?Drive     $drive     = null;
    /** @var array<string, Gmail> settings-only clients (delegates) */
    private array $gmailClients = [];
    /** @var array<string, Gmail> read-write clients (inbox modify + send) */
    private array $gmailRwClients = [];
    private string $domain;
    private bool $enabled;

    public function __construct()
    {
        $this->domain  = config('services.google_workspace.domain', '');
        $this->enabled = (bool) config('services.google_workspace.enabled', false);
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->domain !== ''
            && config('services.google_workspace.admin_email') !== null
            && config('services.google_workspace.credentials') !== null;
    }

    /**
     * Create an account using an admin-specified email address.
     */
    public function createAccountWithEmail(Member $member, string $email): bool
    {
        $result = $this->doCreate($member, $email);
        if ($result !== null) {
            $member->workspaceAccount()->updateOrCreate([], ['email' => $result]);
            return true;
        }
        return false;
    }

    /**
     * Create a Google Workspace account for a member.
     * Returns the new email address, or null on failure.
     */
    public function createAccount(Member $member): ?string
    {
        $email  = $this->resolveEmail($member);
        $result = $this->doCreate($member, $email);

        if ($result !== null) {
            $member->workspaceAccount()->updateOrCreate([], ['email' => $result]);
        }

        return $result;
    }

    /**
     * Create a functional (shared) account not tied to a member.
     */
    public function createSharedAccount(string $email, string $displayName, ?string $description = null): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        $parts = explode(' ', $displayName, 2);

        $user = new GoogleUser([
            'primaryEmail' => $email,
            'name'         => new UserName([
                'givenName'  => $parts[0],
                'familyName' => $parts[1] ?? $parts[0],
            ]),
            'password'                  => Str::random(32),
            'changePasswordAtNextLogin' => false,
        ]);

        try {
            $dir->users->insert($user);
            Log::info('Google Workspace shared account created', ['email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace createSharedAccount failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function doCreate(Member $member, string $email): ?string
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        $user = new GoogleUser([
            'primaryEmail' => $email,
            'name'         => new UserName([
                'givenName'  => $member->first_name,
                'familyName' => $member->last_name,
            ]),
            'password'     => Str::random(32),
            'changePasswordAtNextLogin' => true,
        ]);

        try {
            $dir->users->insert($user);
            Log::info('Google Workspace account created', ['email' => $email, 'member_id' => $member->id]);
            return $email;
        } catch (\Exception $e) {
            Log::error('Google Workspace createAccount failed', [
                'member_id' => $member->id,
                'email'     => $email,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Suspend a Google Workspace account.
     */
    public function suspendAccount(string $email): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        try {
            $dir->users->update($email, new GoogleUser(['suspended' => true]));
            Log::info('Google Workspace account suspended', ['email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace suspendAccount failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Restore (un-suspend) a Google Workspace account.
     */
    public function restoreAccount(string $email): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        try {
            $dir->users->update($email, new GoogleUser(['suspended' => false]));
            Log::info('Google Workspace account restored', ['email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace restoreAccount failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Permanently delete a Google Workspace account.
     * Google retains the account for 5 days before final removal.
     */
    public function deleteAccount(string $email): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        try {
            $dir->users->delete($email);
            Log::info('Google Workspace account deleted', ['email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace deleteAccount failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Fetch per-user usage stats from the Admin SDK Reports API.
     * Returns an array keyed by email with last_login, storage_gmail, storage_drive (bytes), is_2sv.
     * Data is typically 2–3 days old.
     *
     * @return array<string, array{email:string, last_login:\Carbon\Carbon|null, storage_gmail:int, storage_drive:int, is_2sv:bool}>|null
     */
    public function getUserUsageReport(): ?array
    {
        $credentials = config('services.google_workspace.credentials');
        $adminEmail  = config('services.google_workspace.admin_email');

        if (! $credentials || ! $adminEmail) {
            return null;
        }

        try {
            $credentialsArray = is_file($credentials)
                ? json_decode(file_get_contents($credentials), true)
                : json_decode($credentials, true);

            if (! is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($adminEmail);
            $client->addScope(Reports::ADMIN_REPORTS_USAGE_READONLY);

            $service = new Reports($client);

            $params = implode(',', [
                'accounts:last_login_time',
                'accounts:is_2sv_enrolled',
                'accounts:gmail_used_quota_in_mb',
                'accounts:drive_used_quota_in_mb',
                'accounts:total_quota_in_mb',
                'accounts:first_name',
                'accounts:last_name',
            ]);

            $extractRow = function ($report) {
                $email  = $report->getEntity()->getUserEmail();
                $p      = collect($report->getParameters())->keyBy(fn ($x) => $x->getName());

                $lastLogin = $p->get('accounts:last_login_time');
                return [
                    'email'         => $email,
                    'first_name'    => $p->get('accounts:first_name')?->getStringValue() ?? '',
                    'last_name'     => $p->get('accounts:last_name')?->getStringValue() ?? '',
                    'last_login'    => $lastLogin?->getDatetimeValue()
                        ? \Carbon\Carbon::parse($lastLogin->getDatetimeValue())
                        : null,
                    'storage_gmail_mb' => (int) ($p->get('accounts:gmail_used_quota_in_mb')?->getIntValue() ?? 0),
                    'storage_drive_mb' => (int) ($p->get('accounts:drive_used_quota_in_mb')?->getIntValue() ?? 0),
                    'quota_mb'         => (int) ($p->get('accounts:total_quota_in_mb')?->getIntValue() ?? 0),
                    'is_2sv'           => (bool) ($p->get('accounts:is_2sv_enrolled')?->getBoolValue() ?? false),
                ];
            };

            // Try up to 5 days back to find a date with data (Google Reports lag 2–3 days)
            $results = [];
            for ($daysBack = 3; $daysBack <= 7; $daysBack++) {
                $date = now()->subDays($daysBack)->format('Y-m-d');
                try {
                    $page = $service->userUsageReport->get('all', $date, [
                        'parameters' => $params,
                        'maxResults' => 500,
                    ]);

                    foreach ($page->getUsageReports() as $report) {
                        $row = $extractRow($report);
                        $results[$row['email']] = $row;
                    }

                    while ($page->getNextPageToken()) {
                        $page = $service->userUsageReport->get('all', $date, [
                            'parameters' => $params,
                            'maxResults' => 500,
                            'pageToken'  => $page->getNextPageToken(),
                        ]);
                        foreach ($page->getUsageReports() as $report) {
                            $row = $extractRow($report);
                            $results[$row['email']] = $row;
                        }
                    }

                    if (! empty($results)) {
                        return $results;
                    }
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() === 400) {
                        continue;
                    }
                    throw $e;
                }
            }

            return $results ?: null;
        } catch (\Throwable $e) {
            Log::error('Google Workspace Reports API failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create a Google Meet space and return the meeting URI.
     * Requires the "Google Meet API" to be enabled in Cloud Console and the
     * scope https://www.googleapis.com/auth/meetings.space.created added to DWD.
     */
    public function createMeetSpace(): ?string
    {
        $credentials = config('services.google_workspace.credentials');
        $adminEmail  = config('services.google_workspace.admin_email');

        if (! $credentials || ! $adminEmail) {
            return null;
        }

        try {
            $credentialsArray = is_file($credentials)
                ? json_decode(file_get_contents($credentials), true)
                : json_decode($credentials, true);

            if (! is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($adminEmail);
            $client->addScope('https://www.googleapis.com/auth/meetings.space.created');

            $http     = $client->authorize();
            $response = $http->post('https://meet.googleapis.com/v2/spaces', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => '{}',
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['meetingUri'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Google Meet space creation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check whether a Workspace account exists (regardless of suspended state).
     * Returns null if the API is unreachable.
     */
    public function accountExists(string $email): ?bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        try {
            $dir->users->get($email);
            return true;
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            Log::warning('Google Workspace accountExists error', ['email' => $email, 'error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('Google Workspace accountExists error', ['email' => $email, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Reset a user's password and force change on next login.
     * Returns the new temporary password, or null on failure.
     */
    public function resetPassword(string $email): ?string
    {
        $dir = $this->directory();
        if ($dir === null) return null;

        $password = $this->generatePassword();

        try {
            $dir->users->update($email, new GoogleUser([
                'password'                  => $password,
                'changePasswordAtNextLogin' => true,
            ]));
            Log::info('Google Workspace password reset', ['email' => $email]);
            return $password;
        } catch (\Exception $e) {
            Log::error('Google Workspace resetPassword failed', ['email' => $email, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Rename a Workspace account. Splits displayName on first space into given/family name.
     */
    public function renameUser(string $email, string $displayName): bool
    {
        $dir = $this->directory();
        if ($dir === null) return false;

        $parts = explode(' ', $displayName, 2);

        try {
            $dir->users->update($email, new GoogleUser([
                'name' => new UserName([
                    'givenName'  => $parts[0],
                    'familyName' => $parts[1] ?? $parts[0],
                ]),
            ]));
            Log::info('Google Workspace user renamed', ['email' => $email, 'name' => $displayName]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace renameUser failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create account and return ['email' => ..., 'password' => ...], or null on failure.
     */
    public function provisionWithPassword(Member $member, ?string $email = null): ?array
    {
        if (! $this->isConfigured()) return null;

        $wsAccount = $member->workspaceAccount;
        if ($wsAccount) {
            $this->restoreAccount($wsAccount->email);
            $password = $this->resetPassword($wsAccount->email);
            return $password ? ['email' => $wsAccount->email, 'password' => $password] : null;
        }

        $email    = $email ?? $this->resolveEmail($member);
        $password = $this->generatePassword();

        $dir = $this->directory();
        if ($dir === null) return null;

        $user = new GoogleUser([
            'primaryEmail' => $email,
            'name'         => new UserName([
                'givenName'  => $member->first_name,
                'familyName' => $member->last_name,
            ]),
            'password'                  => $password,
            'changePasswordAtNextLogin' => true,
        ]);

        try {
            $dir->users->insert($user);
            $member->workspaceAccount()->create(['email' => $email]);
            Log::info('Google Workspace account provisioned', ['email' => $email, 'member_id' => $member->id]);
            return ['email' => $email, 'password' => $password];
        } catch (\Exception $e) {
            Log::error('Google Workspace provisionWithPassword failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Provision an account: create if new, restore if suspended, no-op if active.
     */
    public function provision(Member $member): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $wsAccount = $member->workspaceAccount;
        if ($wsAccount) {
            $this->restoreAccount($wsAccount->email);
            return $wsAccount->email;
        }

        return $this->createAccount($member);
    }

    /**
     * Deprovision: suspend the account if one exists.
     */
    public function deprovision(Member $member): bool
    {
        $wsEmail = $member->workspaceAccount?->email;
        if (! $this->isConfigured() || ! $wsEmail) {
            return false;
        }

        return $this->suspendAccount($wsEmail);
    }

    // ── Gmail delegates ────────────────────────────────────────────────────────

    /**
     * List delegates for a mailbox. Returns array of ['email', 'status'] or null on error.
     */
    public function getDelegates(string $mailbox): ?array
    {
        $gmail = $this->gmail($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $response = $gmail->users_settings_delegates->listUsersSettingsDelegates($mailbox);
            return array_map(function ($d) {
                return [
                    'email'  => \is_array($d) ? ($d['delegateEmail'] ?? '') : $d->getDelegateEmail(),
                    'status' => \is_array($d) ? ($d['verificationStatus'] ?? '') : $d->getVerificationStatus(),
                ];
            }, $response->getDelegates() ?? []);
        } catch (\Exception $e) {
            Log::error('Google Workspace getDelegates failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add a delegate to a mailbox.
     */
    public function addDelegate(string $mailbox, string $delegateEmail): bool
    {
        $gmail = $this->gmail($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $delegate = new \Google\Service\Gmail\Delegate(['delegateEmail' => $delegateEmail]);
            $result   = $gmail->users_settings_delegates->create($mailbox, $delegate);
            $status   = \is_array($result) ? ($result['verificationStatus'] ?? 'accepted') : ($result->getVerificationStatus() ?? 'accepted');
            WorkspaceDelegate::updateOrCreate(
                ['mailbox' => $mailbox, 'delegate_email' => $delegateEmail],
                ['status' => $status],
            );
            Log::info('Google Workspace delegate added', ['mailbox' => $mailbox, 'delegate' => $delegateEmail]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace addDelegate failed', [
                'mailbox'  => $mailbox,
                'delegate' => $delegateEmail,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove a delegate from a mailbox.
     */
    public function removeDelegate(string $mailbox, string $delegateEmail): bool
    {
        $gmail = $this->gmail($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $gmail->users_settings_delegates->delete($mailbox, $delegateEmail);
            WorkspaceDelegate::where('mailbox', $mailbox)->where('delegate_email', $delegateEmail)->delete();
            Log::info('Google Workspace delegate removed', ['mailbox' => $mailbox, 'delegate' => $delegateEmail]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace removeDelegate failed', [
                'mailbox'  => $mailbox,
                'delegate' => $delegateEmail,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sync all delegates from the API into the workspace_delegates table.
     * Only checks functional (shared) accounts.
     */
    public function syncDelegates(): int
    {
        $sharedEmails = WorkspaceAccount::nonPersonal()->pluck('email')->all();
        $synced       = 0;

        WorkspaceDelegate::whereNotIn('mailbox', $sharedEmails)->delete();

        foreach ($sharedEmails as $mailbox) {
            $delegates = $this->getDelegates($mailbox);
            if ($delegates === null) {
                continue;
            }

            $seen = [];
            foreach ($delegates as $d) {
                WorkspaceDelegate::updateOrCreate(
                    ['mailbox' => $mailbox, 'delegate_email' => $d['email']],
                    ['status' => $d['status']],
                );
                $seen[] = $d['email'];
                $synced++;
            }

            WorkspaceDelegate::where('mailbox', $mailbox)
                ->whereNotIn('delegate_email', $seen)
                ->delete();
        }

        $synced += $this->syncRoleDelegates();

        return $synced;
    }

    /**
     * Sync role-based delegates: for each shared account linked to an org role,
     * add delegates for active members with a workspace email, and remove those
     * who are no longer active in the role. Never touches manually-added delegates.
     */
    public function syncRoleDelegates(): int
    {
        $accounts = WorkspaceAccount::where('accountable_type', 'org_role')
            ->with('accountable.periods.members.member')
            ->get();

        $synced = 0;

        foreach ($accounts as $account) {
            /** @var OrganizationalRole|null $role */
            $role = $account->accountable;
            if (! $role) {
                continue;
            }

            $activePeriod = $role->activePeriod();
            if (! $activePeriod) {
                // No active period — remove all role-provisioned delegates
                WorkspaceDelegate::where('mailbox', $account->email)
                    ->where('source', WorkspaceDelegate::SOURCE_ROLE)
                    ->each(fn ($d) => $this->removeDelegate($account->email, $d->delegate_email));
                continue;
            }

            $activeEmails = $activePeriod->currentMembers()
                ->with('member.workspaceAccount')
                ->get()
                ->filter(fn (OrganizationalRoleMember $m) =>
                    $m->member?->workspaceAccount?->email &&
                    (! $m->active_from || $m->active_from->lte(now())) &&
                    (! $m->active_until || $m->active_until->gte(now()))
                )
                ->map(fn (OrganizationalRoleMember $m) => $m->member->workspaceAccount->email)
                ->unique()
                ->values()
                ->all();

            // Add missing role delegates
            $existing = WorkspaceDelegate::where('mailbox', $account->email)
                ->pluck('delegate_email')
                ->all();

            foreach ($activeEmails as $wsEmail) {
                if (! in_array($wsEmail, $existing, true)) {
                    if ($this->addDelegate($account->email, $wsEmail)) {
                        // addDelegate already upserts in DB, update source to 'role'
                        WorkspaceDelegate::where('mailbox', $account->email)
                            ->where('delegate_email', $wsEmail)
                            ->update(['source' => WorkspaceDelegate::SOURCE_ROLE]);
                        $synced++;
                    }
                }
            }

            // Remove role delegates no longer in the role
            WorkspaceDelegate::where('mailbox', $account->email)
                ->where('source', WorkspaceDelegate::SOURCE_ROLE)
                ->whereNotIn('delegate_email', $activeEmails)
                ->each(function ($d) use ($account) {
                    $this->removeDelegate($account->email, $d->delegate_email);
                });
        }

        return $synced;
    }

    // ── Gmail inbox ────────────────────────────────────────────────────────────

    /**
     * List message IDs from a mailbox matching a Gmail query.
     * Returns array of gmail_id strings, or null on error.
     */
    public function listInboxMessages(string $mailbox, string $query = 'is:unread', int $max = 100): ?array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $ids     = [];
            $pageToken = null;

            do {
                $params = ['q' => $query, 'maxResults' => min($max - count($ids), 100)];
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response  = $gmail->users_messages->listUsersMessages($mailbox, $params);
                $messages  = $response->getMessages() ?? [];
                foreach ($messages as $m) {
                    $ids[] = $m->getId();
                }
                $pageToken = $response->getNextPageToken();
            } while ($pageToken && count($ids) < $max);

            return $ids;
        } catch (\Exception $e) {
            Log::error('Google Workspace listInboxMessages failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get a single message parsed into a structured array.
     * Returns null on error.
     *
     * @return array{gmail_id:string, thread_id:string, message_id:string|null, from_email:string, from_name:string|null, to:string|null, reply_to:string|null, subject:string|null, received_at:int, labels:array, snippet:string, body_text:string|null, body_html:string|null, attachments:array}|null
     */
    public function getInboxMessage(string $mailbox, string $gmailId): ?array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $msg = $gmail->users_messages->get($mailbox, $gmailId, ['format' => 'full']);
            return $this->parseGmailMessage($gmail, $mailbox, $msg);
        } catch (\Exception $e) {
            Log::error('Google Workspace getInboxMessage failed', ['mailbox' => $mailbox, 'id' => $gmailId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Remove the UNREAD label from a message (mark as read).
     */
    public function markInboxMessageRead(string $mailbox, string $gmailId): void
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return;
        }

        try {
            $req = new \Google\Service\Gmail\ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]);
            $gmail->users_messages->modify($mailbox, $gmailId, $req);
        } catch (\Exception $e) {
            Log::error('Google Workspace markInboxMessageRead failed', ['mailbox' => $mailbox, 'id' => $gmailId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Move a message to Trash.
     */
    public function trashInboxMessage(string $mailbox, string $gmailId): void
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return;
        }

        try {
            $gmail->users_messages->trash($mailbox, $gmailId);
        } catch (\Exception $e) {
            Log::error('Google Workspace trashInboxMessage failed', ['mailbox' => $mailbox, 'id' => $gmailId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Add a label (by name) to a message, creating the label first if it doesn't exist.
     */
    public function addGmailLabel(string $mailbox, string $gmailId, string $labelName): void
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return;
        }

        try {
            $labelId = $this->resolveGmailLabelId($gmail, $mailbox, $labelName);
            if ($labelId === null) {
                return;
            }
            $req = new \Google\Service\Gmail\ModifyMessageRequest(['addLabelIds' => [$labelId]]);
            $gmail->users_messages->modify($mailbox, $gmailId, $req);
        } catch (\Exception $e) {
            Log::warning('Google Workspace addGmailLabel failed', ['mailbox' => $mailbox, 'id' => $gmailId, 'error' => $e->getMessage()]);
        }
    }

    private function resolveGmailLabelId(Gmail $gmail, string $mailbox, string $labelName): ?string
    {
        try {
            $response = $gmail->users_labels->listUsersLabels($mailbox);
            foreach ($response->getLabels() ?? [] as $label) {
                if ($label->getName() === $labelName) {
                    return $label->getId();
                }
            }
            $created = $gmail->users_labels->create($mailbox, new \Google\Service\Gmail\Label(['name' => $labelName]));
            return $created->getId();
        } catch (\Exception $e) {
            Log::warning('Google Workspace resolveGmailLabelId failed', ['mailbox' => $mailbox, 'label' => $labelName, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send a reply from the given mailbox.
     */
    public function sendReply(
        string  $mailbox,
        string  $to,
        string  $subject,
        string  $body,
        string  $threadId,
        ?string $inReplyTo = null,
    ): bool {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        $raw = "From: {$mailbox}\r\n"
             . "To: {$to}\r\n"
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

        if ($inReplyTo) {
            $raw .= "In-Reply-To: {$inReplyTo}\r\n"
                  . "References: {$inReplyTo}\r\n";
        }

        $raw .= "\r\n" . $body;

        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $message = new \Google\Service\Gmail\Message([
            'raw'      => $encoded,
            'threadId' => $threadId,
        ]);

        try {
            $gmail->users_messages->send($mailbox, $message);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace sendReply failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ── Gmail parsing helpers ──────────────────────────────────────────────────

    /** @return array{gmail_id:string, thread_id:string, message_id:string|null, from_email:string, from_name:string|null, to:string|null, reply_to:string|null, subject:string|null, received_at:int, labels:array, snippet:string, body_text:string|null, body_html:string|null, attachments:array} */
    private function parseGmailMessage(Gmail $gmail, string $mailbox, \Google\Service\Gmail\Message $msg): array
    {
        $headers = [];
        foreach ($msg->getPayload()->getHeaders() ?? [] as $h) {
            $headers[strtolower($h->getName())] = $h->getValue();
        }

        $bodyText    = null;
        $bodyHtml    = null;
        $attachments = [];
        $this->extractParts($gmail, $mailbox, $msg->getId(), $msg->getPayload(), $bodyText, $bodyHtml, $attachments);

        [$fromName, $fromEmail] = $this->parseEmailHeader($headers['from'] ?? '');

        return [
            'gmail_id'    => $msg->getId(),
            'thread_id'   => $msg->getThreadId(),
            'message_id'  => $headers['message-id'] ?? null,
            'from_email'  => $fromEmail,
            'from_name'   => $fromName,
            'to'          => $headers['to'] ?? null,
            'reply_to'    => $headers['reply-to'] ?? null,
            'subject'     => $headers['subject'] ?? null,
            'received_at' => (int) ($msg->getInternalDate() / 1000), // Unix timestamp
            'labels'      => $msg->getLabelIds() ?? [],
            'snippet'     => html_entity_decode($msg->getSnippet() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'body_text'   => $bodyText,
            'body_html'   => $bodyHtml,
            'attachments' => $attachments,
        ];
    }

    private function extractParts(
        Gmail  $gmail,
        string $mailbox,
        string $messageId,
        mixed  $part,
        ?string &$bodyText,
        ?string &$bodyHtml,
        array  &$attachments,
    ): void {
        $mimeType = $part->getMimeType() ?? '';
        $body     = $part->getBody();
        $filename = $part->getFilename();

        if ($filename) {
            if ($body?->getAttachmentId()) {
                // Large attachment — store metadata only; download via getAttachmentDownload()
                $attachments[] = [
                    'filename'      => $filename,
                    'mime_type'     => $mimeType,
                    'attachment_id' => $body->getAttachmentId(),
                    'size'          => $body->getSize() ?? null,
                ];
            } elseif ($body?->getData()) {
                // Small inline attachment embedded in message — no separate ID
                $attachments[] = [
                    'filename'      => $filename,
                    'mime_type'     => $mimeType,
                    'attachment_id' => null,
                    'size'          => null,
                ];
            }
            return;
        }

        if ($mimeType === 'text/plain' && $bodyText === null && $body?->getData()) {
            $bodyText = base64_decode(strtr($body->getData(), '-_', '+/'));
        } elseif ($mimeType === 'text/html' && $bodyHtml === null && $body?->getData()) {
            $bodyHtml = base64_decode(strtr($body->getData(), '-_', '+/'));
        }

        foreach ($part->getParts() ?? [] as $subPart) {
            $this->extractParts($gmail, $mailbox, $messageId, $subPart, $bodyText, $bodyHtml, $attachments);
        }
    }

    private function parseEmailHeader(string $header): array
    {
        if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $header, $m)) {
            return [trim($m[1]) ?: null, strtolower(trim($m[2]))];
        }

        return [null, strtolower(trim($header))];
    }

    /**
     * List all domains registered in the Workspace account.
     * Returns array of ['domainName', 'isPrimary'] or null on error.
     */
    public function listDomains(): ?array
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        try {
            $response = $dir->domains->listDomains('my_customer');
            $domains  = [];
            foreach ($response->getDomains() ?? [] as $domain) {
                $domains[] = [
                    'domainName' => $domain->getDomainName(),
                    'isPrimary'  => (bool) $domain->getIsPrimary(),
                ];
            }
            usort($domains, fn ($a, $b) => $b['isPrimary'] <=> $a['isPrimary']);
            return $domains;
        } catch (\Exception $e) {
            Log::error('Google Workspace listDomains failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * List aliases for a user. Returns array of email strings, or null on error.
     */
    public function getAliases(string $email): ?array
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        try {
            $response = $dir->users_aliases->listUsersAliases($email);
            return array_map(
                fn ($a) => \is_array($a) ? ($a['alias'] ?? '') : $a->getAlias(),
                $response->getAliases() ?? []
            );
        } catch (\Exception $e) {
            Log::error('Google Workspace getAliases failed', ['email' => $email, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add an alias to a user account. Returns true on success.
     */
    public function addAlias(string $primaryEmail, string $alias): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        try {
            $aliasObj = new \Google\Service\Directory\Alias(['alias' => $alias]);
            $dir->users_aliases->insert($primaryEmail, $aliasObj);
            Log::info('Google Workspace alias added', ['primary' => $primaryEmail, 'alias' => $alias]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace addAlias failed', [
                'primary' => $primaryEmail,
                'alias'   => $alias,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove an alias from a user account.
     */
    public function removeAlias(string $primaryEmail, string $alias): bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }

        try {
            $dir->users_aliases->delete($primaryEmail, $alias);
            Log::info('Google Workspace alias removed', ['primary' => $primaryEmail, 'alias' => $alias]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace removeAlias failed', [
                'primary' => $primaryEmail,
                'alias'   => $alias,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get suspension status for an account. Returns null if unreachable.
     */
    public function isSuspended(string $email): ?bool
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        try {
            $user = $dir->users->get($email);
            return (bool) $user->getSuspended();
        } catch (\Exception $e) {
            Log::warning('Google Workspace isSuspended error', ['email' => $email, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * List all users in the Workspace domain.
     * Returns array of ['email', 'full_name', 'suspended'] or null on error.
     */
    public function listUsers(): ?array
    {
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }

        try {
            $results = [];
            $pageToken = null;

            do {
                $params = ['domain' => $this->domain, 'maxResults' => 500];
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response  = $dir->users->listUsers($params);
                $pageToken = $response->getNextPageToken();

                foreach ($response->getUsers() ?? [] as $user) {
                    $results[] = [
                        'email'      => $user->getPrimaryEmail(),
                        'full_name'  => $user->getName()?->getFullName() ?? '',
                        'given_name' => $user->getName()?->getGivenName() ?? '',
                        'family_name'=> $user->getName()?->getFamilyName() ?? '',
                        'suspended'  => (bool) $user->getSuspended(),
                    ];
                }
            } while ($pageToken);

            return $results;
        } catch (\Exception $e) {
            Log::error('Google Workspace listUsers failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a @domain email address from a first + last name (no uniqueness check).
     */
    public function emailFromName(string $firstName, string $lastName): string
    {
        return $this->toLocalPart($firstName) . '.' . $this->toLocalPart($lastName) . '@' . $this->domain;
    }

    /**
     * Build a unique @domain email address for a member.
     * Transliterates Swedish characters; appends a digit on collision.
     */
    public function resolveEmail(Member $member): string
    {
        $local = $this->toLocalPart($member->first_name) . '.' . $this->toLocalPart($member->last_name);
        $email = $local . '@' . $this->domain;

        if ($this->accountExists($email) === false) {
            return $email;
        }

        // Try appending 2, 3, … until we find a free slot
        for ($i = 2; $i <= 99; $i++) {
            $candidate = $local . $i . '@' . $this->domain;
            if ($this->accountExists($candidate) === false) {
                return $candidate;
            }
        }

        // Last resort: append random suffix
        return $local . '.' . Str::random(4) . '@' . $this->domain;
    }

    // ── Google Drive ───────────────────────────────────────────────────────────

    /**
     * List all Shared Drives in the Workspace. Returns array of ['id', 'name'].
     */
    public function listSharedDrives(): ?array
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return null;
        }

        try {
            $results   = [];
            $pageToken = null;

            do {
                $params = ['useDomainAdminAccess' => true, 'pageSize' => 100];
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }
                $response  = $driveService->drives->listDrives($params);
                $pageToken = \is_array($response) ? ($response['nextPageToken'] ?? null) : $response->getNextPageToken();
                $drives    = \is_array($response) ? ($response['drives'] ?? []) : ($response->getDrives() ?? []);

                foreach ($drives as $d) {
                    $results[] = [
                        'id'   => \is_array($d) ? ($d['id'] ?? '')   : $d->getId(),
                        'name' => \is_array($d) ? ($d['name'] ?? '') : $d->getName(),
                    ];
                }
            } while ($pageToken);

            return $results;
        } catch (\Exception $e) {
            Log::error('Google Workspace listSharedDrives failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create a new Shared Drive and persist it.
     */
    public function createSharedDrive(WorkspaceSharedDrive $record): bool
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return false;
        }

        try {
            $requestId = Str::uuid()->toString();
            $driveObj  = new Drive\Drive(['name' => $record->name]);
            $result    = $driveService->drives->create($requestId, $driveObj);
            $driveId   = \is_array($result) ? ($result['id'] ?? null) : $result->getId();

            if (! $driveId) {
                return false;
            }

            $record->update(['google_drive_id' => $driveId]);
            Log::info('Google Workspace Shared Drive created', ['name' => $record->name, 'drive_id' => $driveId]);

            if ($record->public_access) {
                $this->setPublicAccess($driveId, true);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace createSharedDrive failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * List members of a Shared Drive. Returns array of ['email', 'role', 'permission_id'].
     */
    public function getDriveMembers(string $driveId): ?array
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return null;
        }

        try {
            $response = $driveService->permissions->listPermissions($driveId, [
                'supportsAllDrives' => true,
                'fields'            => 'permissions(id,emailAddress,role,type)',
            ]);
            $perms = \is_array($response) ? ($response['permissions'] ?? []) : ($response->getPermissions() ?? []);

            return array_values(array_filter(array_map(function ($p) {
                $email = \is_array($p) ? ($p['emailAddress'] ?? null) : $p->getEmailAddress();
                $role  = \is_array($p) ? ($p['role'] ?? '')          : $p->getRole();
                $id    = \is_array($p) ? ($p['id'] ?? '')             : $p->getId();
                $type  = \is_array($p) ? ($p['type'] ?? '')           : $p->getType();
                if (! $email || $type !== 'user') {
                    return null;
                }
                return ['email' => $email, 'role' => $role, 'permission_id' => $id];
            }, $perms)));
        } catch (\Exception $e) {
            Log::error('Google Workspace getDriveMembers failed', ['drive_id' => $driveId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add a member to a Shared Drive.
     */
    public function addDriveMember(string $driveId, string $email, string $role, string $source = WorkspaceDriveMember::SOURCE_MANUAL): bool
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return false;
        }

        try {
            $permission = new Drive\Permission([
                'type'         => 'user',
                'role'         => $role,
                'emailAddress' => $email,
            ]);
            $result = $driveService->permissions->create($driveId, $permission, [
                'supportsAllDrives'      => true,
                'sendNotificationEmail'  => false,
                'fields'                 => 'id',
            ]);
            $permId = \is_array($result) ? ($result['id'] ?? '') : $result->getId();

            WorkspaceDriveMember::updateOrCreate(
                ['google_drive_id' => $driveId, 'email' => $email],
                ['permission_id' => $permId, 'role' => $role, 'source' => $source],
            );
            Log::info('Google Workspace Drive member added', ['drive_id' => $driveId, 'email' => $email, 'role' => $role]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace addDriveMember failed', [
                'drive_id' => $driveId, 'email' => $email, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove a member from a Shared Drive by their permission ID.
     */
    public function removeDriveMember(string $driveId, string $permissionId, string $email): bool
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return false;
        }

        try {
            $driveService->permissions->delete($driveId, $permissionId, ['supportsAllDrives' => true]);
            WorkspaceDriveMember::where('google_drive_id', $driveId)->where('email', $email)->delete();
            Log::info('Google Workspace Drive member removed', ['drive_id' => $driveId, 'email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace removeDriveMember failed', [
                'drive_id' => $driveId, 'email' => $email, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sync drive membership for all configured shared drives based on section/role membership.
     */
    public function syncDriveMembers(): int
    {
        $drives = WorkspaceSharedDrive::whereNotNull('google_drive_id')
            ->with([
                'section.memberships.member',
                'organizationalRole.periods.members.member',
            ])
            ->get();

        $synced = 0;

        foreach ($drives as $drive) {
            /** @var WorkspaceSharedDrive $drive */
            $targetEmails = $this->resolveTargetEmails($drive);
            $role         = $drive->member_role;
            $driveId      = $drive->google_drive_id;

            $existing = WorkspaceDriveMember::where('google_drive_id', $driveId)
                ->get()
                ->keyBy('email');

            // Add missing
            foreach ($targetEmails as $email) {
                if (! $existing->has($email)) {
                    if ($this->addDriveMember($driveId, $email, $role, $this->driveSource($drive))) {
                        $synced++;
                    }
                } elseif ($existing[$email]->role !== $role) {
                    // Role changed — update
                    $this->updateDriveMemberRole($driveId, $existing[$email]->permission_id, $email, $role);
                }
            }

            // Remove provisioned members no longer in scope
            $source = $this->driveSource($drive);
            WorkspaceDriveMember::where('google_drive_id', $driveId)
                ->where('source', $source)
                ->whereNotIn('email', $targetEmails)
                ->each(fn ($m) => $this->removeDriveMember($driveId, $m->permission_id, $m->email));

            // Sync public access
            $this->setPublicAccess($driveId, $drive->public_access);
        }

        return $synced;
    }

    private function driveSource(WorkspaceSharedDrive $drive): string
    {
        return $drive->organizational_role_id
            ? WorkspaceDriveMember::SOURCE_ROLE
            : WorkspaceDriveMember::SOURCE_SECTION;
    }

    private function resolveTargetEmails(WorkspaceSharedDrive $drive): array
    {
        if ($drive->organizational_role_id && $drive->organizationalRole) {
            $period = $drive->organizationalRole->activePeriod();
            if (! $period) {
                return [];
            }
            return $period->currentMembers()
                ->with('member.workspaceAccount')
                ->get()
                ->filter(fn (OrganizationalRoleMember $m) =>
                    $m->member?->workspaceAccount?->email &&
                    (! $m->active_from || $m->active_from->lte(now())) &&
                    (! $m->active_until || $m->active_until->gte(now()))
                )
                ->map(fn (OrganizationalRoleMember $m) => $m->member->workspaceAccount->email)
                ->unique()->values()->all();
        }

        if ($drive->section_id && $drive->section) {
            return $drive->section->memberships()
                ->with('member.workspaceAccount')
                ->get()
                ->map(fn ($sm) => $sm->member?->workspaceAccount?->email)
                ->filter()
                ->unique()->values()->all();
        }

        return [];
    }

    private function updateDriveMemberRole(string $driveId, string $permissionId, string $email, string $role): void
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return;
        }
        try {
            $driveService->permissions->update($driveId, $permissionId,
                new Drive\Permission(['role' => $role]),
                ['supportsAllDrives' => true]
            );
            WorkspaceDriveMember::where('google_drive_id', $driveId)
                ->where('email', $email)
                ->update(['role' => $role]);
        } catch (\Exception $e) {
            Log::error('Google Workspace updateDriveMemberRole failed', [
                'drive_id' => $driveId, 'email' => $email, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    public function setPublicAccess(string $driveId, bool $enable): bool
    {
        $driveService = $this->driveService();
        if ($driveService === null) {
            return false;
        }

        try {
            if ($enable) {
                $driveService->permissions->create($driveId,
                    new Drive\Permission(['type' => 'anyone', 'role' => 'reader']),
                    ['supportsAllDrives' => true]
                );
            } else {
                // anyoneWithLink permissions have type=anyone, not user — fetch raw
                $response = $driveService->permissions->listPermissions($driveId, [
                    'supportsAllDrives' => true,
                    'fields'            => 'permissions(id,type)',
                ]);
                $perms = \is_array($response) ? ($response['permissions'] ?? []) : ($response->getPermissions() ?? []);
                foreach ($perms as $p) {
                    $type = \is_array($p) ? ($p['type'] ?? '') : $p->getType();
                    $id   = \is_array($p) ? ($p['id'] ?? '')   : $p->getId();
                    if ($type === 'anyone') {
                        $driveService->permissions->delete($driveId, $id, ['supportsAllDrives' => true]);
                        break;
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace setPublicAccess failed', ['drive_id' => $driveId, 'enable' => $enable, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function driveService(): ?Drive
    {
        if ($this->drive !== null) {
            return $this->drive;
        }

        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $credentials = config('services.google_workspace.credentials');
            $adminEmail  = config('services.google_workspace.admin_email');

            $credentialsArray = is_file($credentials)
                ? json_decode(file_get_contents($credentials), true)
                : json_decode($credentials, true);

            if (! \is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($adminEmail);
            $client->addScope(Drive::DRIVE);

            $this->drive = new Drive($client);
            return $this->drive;
        } catch (\Exception $e) {
            Log::error('Google Workspace Drive client init failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function generatePassword(int $length = 16): string
    {
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $special = '!@#$%^&*';
        $all     = $lower . $upper . $digits . $special;

        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = 4; $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);
        return implode('', $chars);
    }

    private function toLocalPart(string $name): string
    {
        $name = mb_strtolower($name);
        $name = strtr($name, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u', 'ñ' => 'n']);
        // Remove anything that isn't alphanumeric or a hyphen
        return preg_replace('/[^a-z0-9\-]/', '', $name) ?: 'user';
    }

    private function directory(): ?Directory
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $credentials = config('services.google_workspace.credentials');
            $adminEmail  = config('services.google_workspace.admin_email');

            // Accept either a file path or raw JSON string
            if (is_file($credentials)) {
                $credentialsArray = json_decode(file_get_contents($credentials), true);
            } else {
                $credentialsArray = json_decode($credentials, true);
            }

            if (! \is_array($credentialsArray)) {
                Log::error('Google Workspace: invalid credentials format');
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($adminEmail);
            $client->addScope(Directory::ADMIN_DIRECTORY_USER);
            $client->addScope(Directory::ADMIN_DIRECTORY_DOMAIN_READONLY);

            $this->directory = new Directory($client);
            return $this->directory;
        } catch (\Exception $e) {
            Log::error('Google Workspace client init failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build a Gmail client with inbox read/write + send scopes (DWD impersonation).
     */
    private function gmailReadWrite(string $mailbox): ?Gmail
    {
        if (isset($this->gmailRwClients[$mailbox])) {
            return $this->gmailRwClients[$mailbox];
        }

        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $credentials = config('services.google_workspace.credentials');

            if (is_file($credentials)) {
                $credentialsArray = json_decode(file_get_contents($credentials), true);
            } else {
                $credentialsArray = json_decode($credentials, true);
            }

            if (! \is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($mailbox);
            $client->addScope(Gmail::GMAIL_MODIFY);
            $client->addScope(Gmail::GMAIL_SEND);

            $this->gmailRwClients[$mailbox] = new Gmail($client);
            return $this->gmailRwClients[$mailbox];
        } catch (\Exception $e) {
            Log::error('Google Workspace Gmail RW client init failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add the UNREAD label back to a message.
     */
    public function markInboxMessageUnread(string $mailbox, string $gmailId): void
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return;
        }

        try {
            $req = new \Google\Service\Gmail\ModifyMessageRequest(['addLabelIds' => ['UNREAD']]);
            $gmail->users_messages->modify($mailbox, $gmailId, $req);
        } catch (\Exception $e) {
            Log::error('Google Workspace markInboxMessageUnread failed', ['mailbox' => $mailbox, 'id' => $gmailId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get all messages in a thread, parsed.
     *
     * @return array<int, array>|null
     */
    public function getInboxThread(string $mailbox, string $threadId): ?array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $thread   = $gmail->users_threads->get($mailbox, $threadId, ['format' => 'full']);
            $messages = [];
            foreach ($thread->getMessages() ?? [] as $msg) {
                $messages[] = $this->parseGmailMessage($gmail, $mailbox, $msg);
            }
            return $messages;
        } catch (\Exception $e) {
            Log::error('Google Workspace getInboxThread failed', ['mailbox' => $mailbox, 'threadId' => $threadId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Paginated message listing.
     *
     * @return array{messages: array, nextPageToken: string|null}
     */
    public function listInboxMessagesPaginated(string $mailbox, string $query, int $max, ?string $pageToken): array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return ['messages' => [], 'nextPageToken' => null];
        }

        try {
            $params = ['q' => $query, 'maxResults' => min($max, 100)];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $gmail->users_messages->listUsersMessages($mailbox, $params);
            $ids      = array_map(fn ($m) => $m->getId(), $response->getMessages() ?? []);
            $messages = [];

            foreach ($ids as $id) {
                $raw = $gmail->users_messages->get($mailbox, $id, ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Subject', 'Reply-To', 'Message-ID']]);
                $messages[] = $this->parseGmailMessageMeta($raw);
            }

            return [
                'messages'      => $messages,
                'nextPageToken' => $response->getNextPageToken(),
            ];
        } catch (\Exception $e) {
            Log::error('Google Workspace listInboxMessagesPaginated failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return ['messages' => [], 'nextPageToken' => null];
        }
    }

    private function parseGmailMessageMeta(\Google\Service\Gmail\Message $msg): array
    {
        $headers = [];
        foreach ($msg->getPayload()?->getHeaders() ?? [] as $h) {
            $headers[strtolower($h->getName())] = $h->getValue();
        }

        [$fromName, $fromEmail] = $this->parseEmailHeader($headers['from'] ?? '');

        return [
            'gmail_id'     => $msg->getId(),
            'thread_id'    => $msg->getThreadId(),
            'message_id'   => $headers['message-id'] ?? null,
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'to'           => $headers['to'] ?? null,
            'reply_to'     => $headers['reply-to'] ?? null,
            'subject'      => $headers['subject'] ?? null,
            'received_at'  => (int) ($msg->getInternalDate() / 1000), // Unix timestamp
            'labels'       => $msg->getLabelIds() ?? [],
            'snippet'      => html_entity_decode($msg->getSnippet() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    /**
     * Download a single attachment's binary content.
     *
     * @return array{filename: string, mime_type: string, data: string}|null
     */
    public function getAttachmentDownload(string $mailbox, string $messageId, string $attachmentId): ?array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            // First get the message to retrieve filename/mime
            $msg     = $gmail->users_messages->get($mailbox, $messageId, ['format' => 'full']);
            $meta    = $this->findAttachmentMeta($msg->getPayload(), $attachmentId);
            $att     = $gmail->users_messages_attachments->get($mailbox, $messageId, $attachmentId);
            $data    = base64_decode(strtr($att->getData(), '-_', '+/'));

            return [
                'filename'  => $meta['filename'] ?? 'attachment',
                'mime_type' => $meta['mime_type'] ?? 'application/octet-stream',
                'data'      => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Google Workspace getAttachmentDownload failed', ['mailbox' => $mailbox, 'msgId' => $messageId, 'attId' => $attachmentId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function findAttachmentMeta(mixed $part, string $attachmentId): array
    {
        if ($part->getBody()?->getAttachmentId() === $attachmentId) {
            return ['filename' => $part->getFilename(), 'mime_type' => $part->getMimeType()];
        }
        foreach ($part->getParts() ?? [] as $sub) {
            $found = $this->findAttachmentMeta($sub, $attachmentId);
            if ($found) {
                return $found;
            }
        }
        return [];
    }

    /**
     * Send a brand-new message (not a reply).
     */
    public function sendNewMessage(
        string  $mailbox,
        string  $to,
        ?string $cc,
        ?string $bcc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        array   $attachmentFiles = [],
    ): bool {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $raw     = $this->buildRawMime($mailbox, $to, $cc, $bcc, $subject, $body, $isHtml, $attachmentFiles);
            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $gmail->users_messages->send($mailbox, new \Google\Service\Gmail\Message(['raw' => $encoded]));
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace sendNewMessage failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Forward an existing message.
     */
    public function forwardInboxMessage(
        string  $mailbox,
        string  $originalId,
        string  $to,
        ?string $cc,
        string  $note,
    ): bool {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $original = $this->getInboxMessage($mailbox, $originalId);
            if (! $original) {
                return false;
            }

            $fwdSubject = str_starts_with((string) $original['subject'], 'Fwd:')
                ? $original['subject']
                : 'Fwd: ' . $original['subject'];

            $quote = "\r\n\r\n---------- Vidarebefordrat meddelande ----------\r\n"
                . 'Från: ' . ($original['from_name'] ? "{$original['from_name']} <{$original['from_email']}>" : $original['from_email']) . "\r\n"
                . 'Datum: ' . \Illuminate\Support\Carbon::createFromTimestamp($original['received_at'])->locale('sv')->isoFormat('D MMM YYYY, HH:mm') . "\r\n"
                . 'Ämne: ' . ($original['subject'] ?? '') . "\r\n"
                . 'Till: ' . ($original['to'] ?? '') . "\r\n\r\n"
                . ($original['body_text'] ?? strip_tags($original['body_html'] ?? ''));

            $body = $note . $quote;
            $raw  = $this->buildRawMime($mailbox, $to, $cc, null, $fwdSubject, $body, false, []);
            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $gmail->users_messages->send($mailbox, new \Google\Service\Gmail\Message(['raw' => $encoded]));
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace forwardInboxMessage failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send a reply (extended to support CC and HTML body).
     */
    public function sendReplyExtended(
        string  $mailbox,
        string  $to,
        ?string $cc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        string  $threadId,
        ?string $inReplyTo = null,
        array   $attachmentFiles = [],
    ): bool {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $raw = $this->buildRawMime($mailbox, $to, $cc, null, $subject, $body, $isHtml, $attachmentFiles, $inReplyTo);
            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $gmail->users_messages->send($mailbox, new \Google\Service\Gmail\Message([
                'raw'      => $encoded,
                'threadId' => $threadId,
            ]));
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace sendReplyExtended failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ── Gmail drafts ───────────────────────────────────────────────────────────

    /**
     * List all drafts for a mailbox.
     *
     * @return array<int, array{draft_id: string, subject: string|null, to: string|null, snippet: string, updated_at: \Illuminate\Support\Carbon}>
     */
    public function listInboxDrafts(string $mailbox): array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return [];
        }

        try {
            $response = $gmail->users_drafts->listUsersDrafts($mailbox, ['maxResults' => 50]);
            $drafts   = [];

            foreach ($response->getDrafts() ?? [] as $draft) {
                $full = $gmail->users_drafts->get($mailbox, $draft->getId(), ['format' => 'full']);
                $msg  = $full->getMessage();
                if (! $msg) {
                    continue;
                }

                $headers = [];
                foreach ($msg->getPayload()?->getHeaders() ?? [] as $h) {
                    $headers[strtolower($h->getName())] = $h->getValue();
                }

                $drafts[] = [
                    'draft_id'   => $draft->getId(),
                    'subject'    => $headers['subject'] ?? null,
                    'to'         => $headers['to'] ?? null,
                    'snippet'    => html_entity_decode($msg->getSnippet() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'updated_at' => (int) ($msg->getInternalDate() / 1000), // Unix timestamp
                ];
            }

            return $drafts;
        } catch (\Exception $e) {
            Log::error('Google Workspace listInboxDrafts failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a single draft's full data for editing.
     *
     * @return array{draft_id: string, to: string, cc: string, bcc: string, subject: string, body_text: string|null, body_html: string|null, thread_id: string|null}|null
     */
    public function getInboxDraft(string $mailbox, string $draftId): ?array
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $full = $gmail->users_drafts->get($mailbox, $draftId, ['format' => 'full']);
            $msg  = $full->getMessage();
            if (! $msg) {
                return null;
            }

            $headers = [];
            foreach ($msg->getPayload()?->getHeaders() ?? [] as $h) {
                $headers[strtolower($h->getName())] = $h->getValue();
            }

            $bodyText = null;
            $bodyHtml = null;
            $atts     = [];
            $this->extractParts($gmail, $mailbox, $msg->getId() ?? '', $msg->getPayload(), $bodyText, $bodyHtml, $atts);

            return [
                'draft_id'  => $draftId,
                'to'        => $headers['to'] ?? '',
                'cc'        => $headers['cc'] ?? '',
                'bcc'       => $headers['bcc'] ?? '',
                'subject'   => $headers['subject'] ?? '',
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'thread_id' => $msg->getThreadId(),
            ];
        } catch (\Exception $e) {
            Log::error('Google Workspace getInboxDraft failed', ['mailbox' => $mailbox, 'draftId' => $draftId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create or update a draft. Returns the draft ID.
     */
    public function saveInboxDraft(
        string  $mailbox,
        string  $to,
        ?string $cc,
        ?string $bcc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        array   $attachmentFiles = [],
        ?string $existingDraftId = null,
        ?string $threadId = null,
        ?string $inReplyTo = null,
    ): ?string {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return null;
        }

        try {
            $raw     = $this->buildRawMime($mailbox, $to, $cc, $bcc, $subject, $body, $isHtml, $attachmentFiles, $inReplyTo);
            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $gmailMsg = new \Google\Service\Gmail\Message(['raw' => $encoded]);
            if ($threadId) {
                $gmailMsg->setThreadId($threadId);
            }
            $gmailDraft = new \Google\Service\Gmail\Draft(['message' => $gmailMsg]);

            if ($existingDraftId) {
                $gmailDraft->setId($existingDraftId);
                $result = $gmail->users_drafts->update($mailbox, $existingDraftId, $gmailDraft);
            } else {
                $result = $gmail->users_drafts->create($mailbox, $gmailDraft);
            }

            return $result->getId();
        } catch (\Exception $e) {
            Log::error('Google Workspace saveInboxDraft failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send an existing draft.
     */
    public function sendInboxDraft(string $mailbox, string $draftId): bool
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $gmailDraft = new \Google\Service\Gmail\Draft(['id' => $draftId]);
            $gmail->users_drafts->send($mailbox, $gmailDraft);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace sendInboxDraft failed', ['mailbox' => $mailbox, 'draftId' => $draftId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete a draft permanently.
     */
    public function deleteInboxDraft(string $mailbox, string $draftId): bool
    {
        $gmail = $this->gmailReadWrite($mailbox);
        if ($gmail === null) {
            return false;
        }

        try {
            $gmail->users_drafts->delete($mailbox, $draftId);
            return true;
        } catch (\Exception $e) {
            Log::error('Google Workspace deleteInboxDraft failed', ['mailbox' => $mailbox, 'draftId' => $draftId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Build a raw RFC 2822 MIME message string using Symfony Mime.
     *
     * @param array<int, array{content: string, filename: string, mime_type: string}> $attachmentFiles
     */
    private function buildRawMime(
        string  $mailbox,
        string  $to,
        ?string $cc,
        ?string $bcc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        array   $attachmentFiles,
        ?string $inReplyTo = null,
    ): string {
        $email = (new \Symfony\Component\Mime\Email())
            ->from($mailbox)
            ->subject($subject);

        foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
            $email->addTo($addr);
        }
        if ($cc) {
            foreach (array_filter(array_map('trim', explode(',', $cc))) as $addr) {
                $email->addCc($addr);
            }
        }
        if ($bcc) {
            foreach (array_filter(array_map('trim', explode(',', $bcc))) as $addr) {
                $email->addBcc($addr);
            }
        }

        if ($isHtml) {
            $email->html($body);
            $email->text(strip_tags($body));
        } else {
            $email->text($body);
        }

        foreach ($attachmentFiles as $att) {
            $email->attach($att['content'], $att['filename'], $att['mime_type']);
        }

        if ($inReplyTo) {
            $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
            $email->getHeaders()->addTextHeader('References', $inReplyTo);
        }

        return $email->toString();
    }

    /** Expose the RW Gmail client for callers that need direct API access (e.g. labels). */
    public function getGmailRwClient(string $mailbox): ?Gmail
    {
        return $this->gmailReadWrite($mailbox);
    }

    /**
     * Build a Gmail client impersonating the given mailbox (required for delegate API).
     */
    private function gmail(string $mailbox): ?Gmail
    {
        if (isset($this->gmailClients[$mailbox])) {
            return $this->gmailClients[$mailbox];
        }

        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $credentials = config('services.google_workspace.credentials');

            if (is_file($credentials)) {
                $credentialsArray = json_decode(file_get_contents($credentials), true);
            } else {
                $credentialsArray = json_decode($credentials, true);
            }

            if (! \is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($mailbox);
            $client->addScope(Gmail::GMAIL_SETTINGS_BASIC);
            $client->addScope(Gmail::GMAIL_SETTINGS_SHARING);

            $this->gmailClients[$mailbox] = new Gmail($client);
            return $this->gmailClients[$mailbox];
        } catch (\Exception $e) {
            Log::error('Google Workspace Gmail client init failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
