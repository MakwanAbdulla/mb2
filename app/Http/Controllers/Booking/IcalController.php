<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;

class IcalController extends Controller
{
    public function all(?string $token = null): Response
    {
        $user = $token ? User::where('ical_token', $token)->first() : null;

        AuditLog::record(
            action: 'ical_accessed',
            area: 'ical',
            subject: 'Alla rum',
            userId: $user?->id,
        );

        $bookings = Booking::with('resource')
            ->where('status', 'confirmed')
            ->where('ends_at', '>', now()->subMonths(3))
            ->where('starts_at', '<', now()->addMonths(12))
            ->orderBy('starts_at')
            ->get();

        $ical = $this->buildCalendar('Armagedon — alla bokningar', $bookings, null);

        return response($ical, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="armagedon-bokningar.ics"',
        ]);
    }

    public function room(BookingResource $resource, ?string $token = null): Response
    {
        $user = $token ? User::where('ical_token', $token)->first() : null;

        AuditLog::record(
            action: 'ical_accessed',
            area: 'ical',
            subject: $resource->name,
            auditable: $resource,
            userId: $user?->id,
        );

        $bookings = Booking::with('user')
            ->where('resource_id', $resource->id)
            ->where('status', 'confirmed')
            ->where('ends_at', '>', now()->subMonths(3))
            ->where('starts_at', '<', now()->addMonths(12))
            ->orderBy('starts_at')
            ->get();

        $ical = $this->buildCalendar($resource->name, $bookings, $resource->name);

        return response($ical, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $resource->slug . '.ics"',
        ]);
    }

    public function user(string $token): Response
    {
        $user = User::where('ical_token', $token)->firstOrFail();

        AuditLog::record(
            action: 'ical_accessed',
            area: 'ical',
            subject: 'Mina bokningar',
            auditable: $user,
            userId: $user->id,
        );

        $bookings = Booking::with('resource')
            ->where('user_id', $user->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('ends_at', '>', now()->subMonths(3))
            ->where('starts_at', '<', now()->addMonths(12))
            ->orderBy('starts_at')
            ->get();

        $ical = $this->buildCalendar('Mina bokningar', $bookings, null);

        return response($ical, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mina-bokningar.ics"',
        ]);
    }

    private function buildCalendar(string $calName, $bookings, ?string $defaultLocation): string
    {
        $dtstamp = now()->format('Ymd\THis\Z');
        $lines   = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Armagedon//Bokningssystem//SV',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escText($calName),
            'X-WR-TIMEZONE:Europe/Stockholm',
            'X-WR-CALDESC:Armagedon bokningssystem',
        ];

        foreach ($bookings as $booking) {
            $dtstart  = $this->formatDt($booking->starts_at);
            $dtend    = $this->formatDt($booking->ends_at);
            $summary  = $this->escText($booking->title);
            $location = $this->escText($defaultLocation ?? ($booking->resource->name ?? ''));
            $desc     = $this->buildDescription($booking);
            $uid      = 'booking-' . $booking->id . '@armageddon';

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $dtstamp;
            $lines[] = 'DTSTART:' . $dtstart;
            $lines[] = 'DTEND:' . $dtend;
            $lines[] = 'SUMMARY:' . $summary;
            if ($location) {
                $lines[] = 'LOCATION:' . $location;
            }
            if ($desc) {
                $lines[] = 'DESCRIPTION:' . $desc;
            }
            $lines[] = 'STATUS:' . ($booking->status === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function formatDt(Carbon $dt): string
    {
        return $dt->format('Ymd\THis\Z');
    }

    private function escText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        $text = str_replace("\n", '\n', $text);

        return $text;
    }

    private function buildDescription(Booking $booking): string
    {
        $parts = [];

        if ($booking->activity) {
            $parts[] = 'Aktivitet: ' . $booking->activity;
        }

        $type    = $booking->booking_type === 'open' ? 'Öppet arrangemang' : 'Fylld grupp';
        $parts[] = 'Typ: ' . $type;

        if ($booking->participation_info) {
            $parts[] = 'Info: ' . $booking->participation_info;
        }

        if ($booking->notes) {
            $parts[] = 'Anteckning: ' . $booking->notes;
        }

        return implode('\n', array_map([$this, 'escText'], $parts));
    }
}
