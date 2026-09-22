<?php

namespace App\Notifications;

use App\Enums\TaskRunStatus;
use App\Models\TaskRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the people who can clear a gate that something is sitting at it.
 *
 * An approval queue nobody is told about is a queue nobody empties, and work
 * that sits there is exactly what sends someone back to a personal ChatGPT tab.
 */
class WorkAwaitingDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TaskRun $taskRun) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->taskRun;
        $needsExpert = $run->status === TaskRunStatus::AwaitingExpert;

        $message = (new MailMessage)
            ->subject(__('":task" is waiting for you', ['task' => $run->skill->name]))
            ->line(__(':requester ran ":task" and it cannot be used until someone signs it off.', [
                'requester' => $run->requester->name,
                'task' => $run->skill->name,
            ]));

        if ($needsExpert) {
            $message->line(__('This one needs a credentialed professional: :reason', [
                'reason' => $run->skill->supervision_note,
            ]))
                ->line(__('You can send it to your accountant or attorney from the page below.'));
        } else {
            $message->line(__('Read it before it is used or circulated: :reason', [
                'reason' => $run->skill->supervision_note,
            ]));
        }

        return $message->action(
            __('Review it'),
            route('tasks.show', ['current_team' => $run->team->slug, 'taskRun' => $run->id]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_run_id' => $this->taskRun->id,
            'status' => $this->taskRun->status->value,
        ];
    }
}
