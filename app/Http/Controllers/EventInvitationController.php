<?php

namespace App\Http\Controllers;

use App\Models\Events\Event;
use App\Models\Events\EventInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventInvitationController extends Controller
{
    public function __invoke(Request $request, Event $event, string $token): RedirectResponse
    {
        $invitation = EventInvitation::where('event_id', $event->id)
            ->where('token', $token)
            ->first();

        if (! $invitation || $invitation->isExpired()) {
            return redirect()->route('events.show', $event->slug)
                ->with('error', 'Inbjudan är ogiltig eller har gått ut.');
        }

        $member = $request->user()?->member;
        if ($member && $invitation->member_id && $invitation->member_id !== $member->id) {
            return redirect()->route('events.show', $event->slug)
                ->with('error', 'Inbjudan tillhör ett annat konto.');
        }

        if (! $invitation->isAccepted()) {
            $invitation->accept();

            if ($member && ! $invitation->member_id) {
                $invitation->update(['member_id' => $member->id]);
            }
        }

        return redirect()->route('events.show', $event->slug)
            ->with('success', 'Inbjudan accepterad! Välkommen att anmäla dig.');
    }
}
