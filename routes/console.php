<?php

use App\Models\ExpertInvitation;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// The calendar is only useful if it speaks without being opened. Weekday
// mornings: nobody needs a nudge about a 990 on a Sunday.
Schedule::command('work:notify-due')
    ->weekdays()
    ->at('08:00')
    ->withoutOverlapping()
    ->description('Mail each organization the work its calendar says is due');

// Expired expert links are dead weight, and one sitting in an inbox reads as
// if it still works.
Schedule::call(function () {
    ExpertInvitation::query()
        ->where('expires_at', '<', now()->subMonth())
        ->whereNull('used_at')
        ->delete();
})->daily()->description('Delete long-expired expert invitations');
