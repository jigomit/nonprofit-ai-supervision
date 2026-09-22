<?php

namespace App\Notifications;

use App\Models\TaskSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * One mail listing everything on the calendar that has come due.
 *
 * A digest rather than one mail per item: a calendar that mails six times on
 * the first of the month gets filtered, and then it may as well not exist.
 */
class OverdueWorkDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, TaskSchedule>  $schedules
     */
    public function __construct(public Collection $schedules) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->schedules->first();
        $team = $first->team;
        $count = $this->schedules->count();

        $message = (new MailMessage)
            ->subject(trans_choice(
                '{1} One piece of work is due at :organization|[2,*] :count pieces of work are due at :organization',
                $count,
                ['count' => $count, 'organization' => $team->name],
            ))
            ->line(__('These are on your calendar and have come round:'));

        foreach ($this->schedules as $schedule) {
            $message->line(sprintf(
                '• %s — due %s%s',
                $schedule->skill->name,
                $schedule->next_due_at->toFormattedDateString(),
                $schedule->isOverdue() ? ' (overdue)' : '',
            ));
        }

        return $message
            ->action(__('Open the calendar'), route('schedules.index', ['current_team' => $team->slug]))
            ->line(__('Nothing starts on its own — open one and run it when you are ready.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['schedule_ids' => $this->schedules->pluck('id')->all()];
    }
}
