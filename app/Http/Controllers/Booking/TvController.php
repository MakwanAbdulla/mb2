<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Content\Notice;
use Illuminate\Http\Response;

class TvController extends Controller
{
    public function index(): Response
    {
        $now   = now();
        $rooms = BookingResource::where('active', true)->orderBy('name')->get();

        $roomData = $rooms->map(function (BookingResource $room) use ($now) {
            $current = Booking::where('resource_id', $room->id)
                ->whereIn('status', ['confirmed', 'pending'])
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->first();

            $upcoming = Booking::where('resource_id', $room->id)
                ->whereIn('status', ['confirmed', 'pending'])
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<', $now->copy()->addHours(24))
                ->orderBy('starts_at')
                ->limit(2)
                ->get();

            return [
                'room'     => $room,
                'current'  => $current,
                'upcoming' => $upcoming,
            ];
        });

        $nextChangeAt = null;
        foreach ($roomData as $data) {
            $candidates = [];
            if ($data['current']) {
                $candidates[] = $data['current']->ends_at;
            }
            foreach ($data['upcoming'] as $booking) {
                $candidates[] = $booking->starts_at;
            }
            foreach ($candidates as $time) {
                if ($nextChangeAt === null || $time->lt($nextChangeAt)) {
                    $nextChangeAt = $time;
                }
            }
        }

        $notices = Notice::active()->orderBy('starts_at')->get();

        return response()
            ->view('booking.tv.index', [
                'roomData'     => $roomData,
                'nextChangeAt' => $nextChangeAt,
                'generatedAt'  => $now,
                'notices'      => $notices,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function room(BookingResource $resource): Response
    {
        if (! $resource->active) {
            abort(404);
        }

        $now = now();

        $current = Booking::where('resource_id', $resource->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->first();

        $upcoming = Booking::where('resource_id', $resource->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<', $now->copy()->addHours(48))
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        $nextChangeAt = null;
        $candidates   = collect([$current?->ends_at])->merge($upcoming->pluck('starts_at'))->filter();
        foreach ($candidates as $time) {
            if ($nextChangeAt === null || $time->lt($nextChangeAt)) {
                $nextChangeAt = $time;
            }
        }

        return response()
            ->view('booking.tv.room', [
                'room'         => $resource,
                'current'      => $current,
                'upcoming'     => $upcoming,
                'nextChangeAt' => $nextChangeAt,
                'generatedAt'  => $now,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
