<?php

namespace App\Notifications;

use App\Enums\ApprovalDecision;
use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever asked for the work what happened to it.
 *
 * A release without expert review says so plainly. The person who requested it
 * is the one most likely to use the output, and they should know what standing
 * it was cleared at before they do.
 */
class DecisionRecorded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Approval $approval) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->approval->taskRun;
        $decision = $this->approval->decision;

        $subject = match ($decision) {
            ApprovalDecision::Approved => __('":task" is approved', ['task' => $run->skill->name]),
            ApprovalDecision::Rejected => __('":task" was sent back', ['task' => $run->skill->name]),
            ApprovalDecision::ReleasedWithoutExpert => __('":task" was released without expert review', ['task' => $run->skill->name]),
        };

        $message = (new MailMessage)
            ->subject($subject)
            ->line($this->approval->summary().'.');

        if ($decision === ApprovalDecision::ReleasedWithoutExpert) {
            $message->line(__('This is recorded as released without the professional sign-off its level calls for, and appears on the board report.'));

            if ($this->approval->justification !== null) {
                $message->line(__('Reason given: :reason', ['reason' => $this->approval->justification]));
            }
        }

        if ($decision === ApprovalDecision::Rejected) {
            $message->line(__('Nothing was released. Start a fresh run when you are ready to try again.'));
        }

        return $message->action(
            __('Open it'),
            route('tasks.show', ['current_team' => $run->team->slug, 'taskRun' => $run->id]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_run_id' => $this->approval->task_run_id,
            'decision' => $this->approval->decision->value,
        ];
    }
}
