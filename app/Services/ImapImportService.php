<?php

namespace App\Services;

use App\Models\ImapImportJob;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

class ImapImportService
{
    /** IMAP folder names that map to Gmail system labels. */
    private const SYSTEM_FOLDER_MAP = [
        'INBOX'              => ['INBOX'],
        'Sent'               => ['SENT'],
        'Sent Items'         => ['SENT'],
        'Sent Messages'      => ['SENT'],
        '[Gmail]/Sent Mail'  => ['SENT'],
        'Drafts'             => ['DRAFT'],
        'Draft'              => ['DRAFT'],
        '[Gmail]/Drafts'     => ['DRAFT'],
        'Trash'              => ['TRASH'],
        'Deleted'            => ['TRASH'],
        'Deleted Items'      => ['TRASH'],
        '[Gmail]/Trash'      => ['TRASH'],
        'Spam'               => ['SPAM'],
        'Junk'               => ['SPAM'],
        'Junk E-mail'        => ['SPAM'],
        '[Gmail]/Spam'       => ['SPAM'],
    ];

    /**
     * Test IMAP credentials. Returns message count on success.
     * Throws \RuntimeException with a user-facing message on failure.
     */
    public function testConnection(string $host, int $port, string $encryption, string $username, string $password): int
    {
        $cm = new ClientManager();

        try {
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port,
                'encryption'    => $encryption === 'none' ? false : $encryption,
                'validate_cert' => true,
                'username'      => $username,
                'password'      => $password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $count = $client->getFolder('INBOX')->query()->all()->count();
            $client->disconnect();

            return $count;
        } catch (\Exception $e) {
            Log::info('IMAP test connection failed', ['host' => $host, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Kunde inte ansluta: ' . $this->friendlyError($e->getMessage()));
        }
    }

    /**
     * Run the full import for a job. Updates the job record with progress.
     * Safe to call repeatedly — resumes from folders_progress.
     */
    public function import(ImapImportJob $job): void
    {
        $job->update(['status' => ImapImportJob::STATUS_RUNNING, 'started_at' => now(), 'error' => null]);

        $workspaceEmail = $job->user->member?->workspaceAccount?->email;
        if (! $workspaceEmail) {
            $job->update(['status' => ImapImportJob::STATUS_FAILED, 'error' => 'Användaren saknar Google Workspace-adress.']);
            return;
        }

        $gmail = $this->buildGmailClient($workspaceEmail);
        if (! $gmail) {
            $job->update(['status' => ImapImportJob::STATUS_FAILED, 'error' => 'Kunde inte ansluta till Gmail API.']);
            return;
        }

        $cm = new ClientManager();

        try {
            $imap = $cm->make([
                'host'          => $job->host,
                'port'          => $job->port,
                'encryption'    => $job->encryption === 'none' ? false : $job->encryption,
                'validate_cert' => true,
                'username'      => $job->username,
                'password'      => $job->password,
                'protocol'      => 'imap',
            ]);

            $imap->connect();
        } catch (\Exception $e) {
            $job->update(['status' => ImapImportJob::STATUS_FAILED, 'error' => 'IMAP-anslutning misslyckades: ' . $e->getMessage()]);
            return;
        }

        try {
            $folders = $imap->getFolders();
            $gmailLabels = $this->fetchGmailLabels($gmail, $workspaceEmail);

            // Count total messages for progress display
            if ($job->messages_total === null) {
                $total = 0;
                foreach ($folders as $folder) {
                    try { $total += $folder->query()->all()->count(); } catch (\Exception) {}
                }
                $job->update(['messages_total' => $total]);
            }

            foreach ($folders as $folder) {
                $folderName = $folder->path;

                $job->update(['current_folder' => $folderName]);

                $lastUid = $job->lastUidFor($folderName);

                try {
                    $query = $folder->query()->all();
                    if ($lastUid > 0) {
                        $query->where(['UID', $lastUid . ':*']);
                    }
                    $messages = $query->setFetchOrder('asc')->get();
                } catch (\Exception $e) {
                    Log::warning('IMAP folder fetch failed', ['folder' => $folderName, 'error' => $e->getMessage()]);
                    continue;
                }

                $labelIds = $this->resolveGmailLabels($folderName, $gmail, $workspaceEmail, $gmailLabels);

                foreach ($messages as $message) {
                    $uid = $message->uid;

                    if ($uid <= $lastUid) continue;

                    try {
                        $raw = $message->header->raw . "\r\n\r\n" . $message->getRawBody();
                        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

                        $gmailMessage = new Message();
                        $gmailMessage->setRaw($encoded);
                        $gmailMessage->setLabelIds($labelIds);

                        $gmail->users_messages->insert($workspaceEmail, $gmailMessage, [
                            'internalDateSource' => 'dateHeader',
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Gmail insert failed', ['uid' => $uid, 'folder' => $folderName, 'error' => $e->getMessage()]);
                    }

                    $job->markFolderProgress($folderName, $uid);
                    $job->increment('messages_imported');

                    $lastUid = $uid;
                }
            }

            $imap->disconnect();

            $job->update([
                'status'         => ImapImportJob::STATUS_COMPLETED,
                'completed_at'   => now(),
                'current_folder' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('IMAP import failed', ['job' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => ImapImportJob::STATUS_FAILED, 'error' => $e->getMessage()]);
        }
    }

    private function fetchGmailLabels(Gmail $gmail, string $email): array
    {
        $result = $gmail->users_labels->listUsersLabels($email);
        $map = [];
        foreach ($result->getLabels() as $label) {
            $map[$label->getName()] = $label->getId();
        }
        return $map;
    }

    /** Returns array of Gmail label IDs for the given IMAP folder name. */
    private function resolveGmailLabels(string $folder, Gmail $gmail, string $email, array &$labelCache): array
    {
        if (isset(self::SYSTEM_FOLDER_MAP[$folder])) {
            return self::SYSTEM_FOLDER_MAP[$folder];
        }

        // Custom folder → ensure label exists, apply label + INBOX
        if (! isset($labelCache[$folder])) {
            try {
                $label = new Label();
                $label->setName($folder);
                $created = $gmail->users_labels->create($email, $label);
                $labelCache[$folder] = $created->getId();
            } catch (\Exception $e) {
                // Label may already exist under a different casing — ignore
                Log::info('Gmail label create skipped', ['folder' => $folder, 'error' => $e->getMessage()]);
                return ['INBOX'];
            }
        }

        return [$labelCache[$folder]];
    }

    private function buildGmailClient(string $mailbox): ?Gmail
    {
        $credentials = config('services.google_workspace.credentials');
        if (empty($credentials)) return null;

        try {
            $credentialsArray = is_file($credentials)
                ? json_decode(file_get_contents($credentials), true)
                : json_decode($credentials, true);

            if (! \is_array($credentialsArray)) return null;

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($mailbox);
            $client->addScope(Gmail::MAIL_GOOGLE_COM);

            return new Gmail($client);
        } catch (\Exception $e) {
            Log::error('ImapImportService: Gmail client init failed', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function friendlyError(string $message): string
    {
        if (str_contains($message, 'Connection refused'))  return 'Servern nekade anslutningen. Kontrollera host och port.';
        if (str_contains($message, 'Certificate'))         return 'SSL-certifikatet kunde inte verifieras.';
        if (str_contains($message, 'authentication'))      return 'Felaktigt användarnamn eller lösenord.';
        if (str_contains($message, 'Login failed'))        return 'Inloggningen misslyckades. Kontrollera uppgifterna.';
        if (str_contains($message, 'getaddrinfo'))         return 'Servern hittades inte. Kontrollera hostnamnet.';
        return $message;
    }
}
