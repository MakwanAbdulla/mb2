<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class GdprExportService
{
    public function export(User $user): array
    {
        $member = $user->member;

        return [
            'exported_at' => now()->toIso8601String(),
            'konto'       => $this->konto($user),
            'medlem'      => $this->medlem($member),
            'anmalningar' => $this->eventRegistrations($user, $member),
            'ordrar'      => $this->orders($user),
            'bokningar'   => $this->bookings($user),
            'hyror'       => $this->rentals($member),
            'enkater'     => $this->surveys($user),
            'rfid_taggar' => $this->rfidTags($member),
            'mail_log'    => $this->mailLog($user),
            'aktivitetslogg' => $this->auditLog($user),
        ];
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    private function konto(User $user): array
    {
        return [
            'namn'           => $user->name,
            'e_post'         => $user->email,
            'skapad'         => $user->created_at?->toIso8601String(),
            'senaste_inlogg' => $user->last_login_at?->toIso8601String(),
            'google_kopplat' => (bool) $user->google_id,
        ];
    }

    private function medlem(?object $member): array
    {
        if (! $member) return [];
        return [
            'fornamn'         => $member->first_name,
            'efternamn'       => $member->last_name,
            'e_post'          => $member->email,
            'telefon'         => $member->phone,
            'adress'          => $member->address,
            'postnummer'      => $member->postal_code,
            'ort'             => $member->city,
            'fodelsedatum'    => $member->date_of_birth?->toDateString(),
            'medlemstyp'      => $member->membership_type,
            'status'          => $member->status,
            'medlemsnummer'   => $member->membership_number,
            'medlemsperioder' => DB::table('membership_periods')
                ->where('member_id', $member->id)
                ->orderBy('starts_at')
                ->get(['starts_at', 'ends_at', 'type', 'status'])
                ->toArray(),
        ];
    }

    private function eventRegistrations(User $user, ?object $member): array
    {
        return DB::table('event_registrations')
            ->join('events', 'events.id', '=', 'event_registrations.event_id')
            ->where('event_registrations.user_id', $user->id)
            ->orderBy('event_registrations.created_at')
            ->get([
                'events.title as evenemang',
                'event_registrations.status',
                'event_registrations.created_at as anmald',
                'event_registrations.cancelled_at',
            ])
            ->toArray();
    }

    private function orders(User $user): array
    {
        return DB::table('orders')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get(['payment_reference', 'status', 'total_ore', 'paid_at', 'created_at'])
            ->toArray();
    }

    private function bookings(User $user): array
    {
        return DB::table('bookings')
            ->join('booking_resources', 'booking_resources.id', '=', 'bookings.resource_id')
            ->where('bookings.user_id', $user->id)
            ->orderBy('bookings.starts_at')
            ->get([
                'booking_resources.name as lokal',
                'bookings.title',
                'bookings.starts_at',
                'bookings.ends_at',
                'bookings.status',
            ])
            ->toArray();
    }

    private function rentals(?object $member): array
    {
        if (! $member) return [];
        return DB::table('rentals')
            ->join('rental_items', 'rental_items.id', '=', 'rentals.rental_item_id')
            ->join('rental_item_types', 'rental_item_types.id', '=', 'rental_items.rental_item_type_id')
            ->where('rentals.member_id', $member->id)
            ->orderBy('rentals.started_at')
            ->get([
                'rental_item_types.name as typ',
                'rental_items.label as plats',
                'rentals.started_at',
                'rentals.ended_at',
            ])
            ->toArray();
    }

    private function surveys(User $user): array
    {
        $invitations = DB::table('survey_invitations')
            ->join('surveys', 'surveys.id', '=', 'survey_invitations.survey_id')
            ->where('survey_invitations.email', $user->email)
            ->get(['surveys.title as enkat', 'survey_invitations.responded_at', 'survey_invitations.created_at as inbjuden'])
            ->toArray();

        return $invitations;
    }

    private function rfidTags(?object $member): array
    {
        if (! $member) return [];
        return DB::table('member_tags')
            ->where('member_id', $member->id)
            ->get(['status', 'issued_at', 'deactivated_at'])
            ->toArray();
    }

    private function mailLog(User $user): array
    {
        return DB::table('mail_log')
            ->where('recipient_email', $user->email)
            ->orderBy('sent_at')
            ->get(['subject', 'mail_type', 'sent_at'])
            ->toArray();
    }

    private function auditLog(User $user): array
    {
        return DB::table('audit_logs')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get(['action', 'area', 'subject', 'ip_address', 'created_at'])
            ->toArray();
    }

    // ── Summary counts for UI ─────────────────────────────────────────────────

    public function summaryCounts(User $user): array
    {
        $member = $user->member;
        $memberId = $member?->id;

        return [
            'anmalningar'    => DB::table('event_registrations')->where('user_id', $user->id)->count(),
            'ordrar'         => DB::table('orders')->where('user_id', $user->id)->count(),
            'bokningar'      => DB::table('bookings')->where('user_id', $user->id)->count(),
            'hyror'          => $memberId ? DB::table('rentals')->where('member_id', $memberId)->count() : 0,
            'enkater'        => DB::table('survey_invitations')->where('email', $user->email)->count(),
            'rfid_taggar'    => $memberId ? DB::table('member_tags')->where('member_id', $memberId)->count() : 0,
            'mail_log'       => DB::table('mail_log')->where('recipient_email', $user->email)->count(),
            'aktivitetslogg' => DB::table('audit_logs')->where('user_id', $user->id)->count(),
        ];
    }
}
