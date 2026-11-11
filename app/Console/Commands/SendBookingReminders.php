<?php

namespace App\Console\Commands;

use App\Mail\BookingReminder;
use App\Models\Booking\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendBookingReminders extends Command
{
    protected $signature   = 'bookings:send-reminders';
    protected $description = 'Skickar påminnelse-epost för bokningar som startar imorgon';

    public function handle(): void
    {
        $start = now()->addDay()->startOfDay();
        $end   = now()->addDay()->endOfDay();

        Booking::with(['user', 'resource'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereBetween('starts_at', [$start, $end])
            ->whereHas('user', fn ($q) => $q->where('reminder_emails', true)->whereNotNull('email'))
            ->each(function (Booking $booking) {
                Mail::to($booking->user->email)->queue(new BookingReminder($booking));
                $this->line("Skickar påminnelse till {$booking->user->email} för bokning #{$booking->id}");
            });
    }
}
