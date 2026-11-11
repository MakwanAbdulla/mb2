<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/auth/google/callback',
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cogworks' => [
        'org_code'  => env('COGWORKS_ORG_CODE'),
        'api_token' => env('COGWORKS_API_TOKEN'),
    ],

    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_PDF_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    // Inbox mailboxes (office + accounting) are configured in the inbox_mailboxes DB table.

    'zettle' => [
        'client_id' => env('ZETTLE_CLIENT_ID'),
        'assertion' => env('ZETTLE_ASSERTION'),
    ],

    'sumup' => [
        'api_key'    => env('SUMUP_API_KEY'),
        'public_key' => env('SUMUP_PUBLIC_KEY'),
    ],

    // Inleed DocSign — BankID e-signing. See issue #134.
    'docsign' => [
        'api_key' => env('DOCSIGN_API_KEY'),
        'base_url' => env('DOCSIGN_BASE_URL', 'https://docsign.se/api'),
        // Free test documents without real signing. On by default everywhere except
        // APP_ENV=production — set DOCSIGN_DEMO=true on any test environment that
        // happens to run as production.
        'demo' => (bool) env('DOCSIGN_DEMO', env('APP_ENV') !== 'production'),
        'send_reminders' => true,
        'send_receipt' => true,
        'timeout' => 30,
        // Callbacks are only accepted from these addresses (confirmed by Inleed 2026-10-26).
        // Comma-separated, CIDR allowed. Empty = any address, the token still applies.
        'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOCSIGN_CALLBACK_IPS', '194.68.59.169,194.68.59.115'))))),
        // signing:sync logs a warning below this balance (öre).
        'low_balance_ore' => 5000,
        // signing:prune-external anonymizes external signers this many years after their
        // last request closed. Unset = pruning disabled until the association decides.
        'external_retention_years' => env('DOCSIGN_EXTERNAL_RETENTION_YEARS'),
        // Appended to every request's message to signers (editable on the signing page).
        'signer_notice' => 'Föreningen Armagedon sparar ditt namn och dina kontaktuppgifter för att genomföra signeringen och arkivera det signerade dokumentet.',
        // Environments share one DocSign account, so our signer ids are prefixed
        // when sent as external_id — otherwise local signer 1 and staging signer 1
        // would be the same party to DocSign.
        'external_id_prefix' => env('DOCSIGN_EXTERNAL_ID_PREFIX', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'membrum'),
    ],

    'discord' => [
        'client_id'     => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/auth/discord/callback',
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'guild_id'  => env('DISCORD_GUILD_ID'),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
    ],

    'google_workspace' => [
        'enabled'     => env('GOOGLE_WORKSPACE_ENABLED', false),
        'domain'      => env('GOOGLE_WORKSPACE_DOMAIN'),
        'admin_email' => env('GOOGLE_WORKSPACE_ADMIN_EMAIL'),
        // Path to service account JSON file, or the JSON itself as a string
        'credentials' => env('GOOGLE_WORKSPACE_CREDENTIALS'),
    ],


];
