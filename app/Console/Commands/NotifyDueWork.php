<?php

namespace App\Console\Commands;

use App\Models\TaskSchedule;
use App\Models\Team;
use App\Notifications\OverdueWorkDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Mails each organization a digest of the work its calendar says is due.
 *
 * Without this the calendar only speaks when someone happens to open it, which
 * is the same as not having one.
 */
class NotifyDueWork extends Command
{
    protected $signature = 'work:notify-due {--dry-run : List what would be sent without sending it}';

    protected $description = 'Mail each organization the work its calendar says is due';

    public function handle(): int
    {
        $due = TaskSchedule::query()
            ->due()
            ->with(['skill', 'team'])
            ->get()
            ->groupBy('team_id');

        if ($due->isEmpty()) {
            $this->components->info('Nothing is due.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ($due as $teamId => $schedules) {
            $team = Team::find($teamId);

            if ($team === null) {
                continue;
            }

            // Everyone on the team gets it: the point is that somebody picks
            // the work up, not that a particular person is accountable.
            $recipients = $team->members()->get();

            $this->components->twoColumnDetail(
                $team->name,
                $schedules->count().' due · '.$recipients->count().' recipient(s)',
            );

            foreach ($schedules as $schedule) {
                $this->line(sprintf(
                    '    %s — %s%s',
                    $schedule->skill->name,
                    $schedule->next_due_at->toDateString(),
                    $schedule->isOverdue() ? ' (overdue)' : '',
                ));
            }

            if (! $dryRun && $recipients->isNotEmpty()) {
                Notification::send(
                    $recipients->all(),
                    new OverdueWorkDigest($schedules),
                );
                $sent++;
            }
        }

        $this->newLine();

        $this->components->info($dryRun
            ? 'Dry run — nothing was sent.'
            : "Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
