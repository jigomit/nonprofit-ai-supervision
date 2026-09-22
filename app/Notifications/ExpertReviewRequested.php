<?php

namespace App\Notifications;

use App\Models\ExpertInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an outside accountant or attorney the organization already works
 * with. They have no account here, so this goes to a bare address and the link
 * is the only way in.
 *
 * The mail says why their sign-off is being asked for, in the library's own
 * words, because that is what lets them judge whether to give it.
 */
class ExpertReviewRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ExpertInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->invitation->taskRun;
        $team = $run->team;

        return (new MailMessage)
            ->subject(__(':organization has asked you to review a document', [
                'organization' => $team->name,
            ]))
            ->greeting($this->invitation->name !== null
                ? __('Hello :name,', ['name' => $this->invitation->name])
                : __('Hello,'))
            ->line(__(':inviter at :organization has prepared ":task" with AI assistance and needs a credentialed professional to review it before it is used.', [
                'inviter' => $this->invitation->inviter->name,
                'organization' => $team->name,
                'task' => $run->skill->name,
            ]))
            ->line(__('Why a professional review is required: :reason', [
                'reason' => $run->skill->supervision_note,
            ]))
            ->action(__('Read it and decide'), route('expert.review', $this->invitation->token))
            ->line(__('The link works once and expires on :date. You do not need an account.', [
                'date' => $this->invitation->expires_at->toFormattedDateString(),
            ]))
            ->salutation(__('Thank you.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'task_run_id' => $this->invitation->task_run_id,
        ];
    }
}
