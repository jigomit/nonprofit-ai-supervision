<?php

namespace App\Actions;

use App\Models\TaskRun;
use App\Models\TaskRunAttachment;
use App\Services\AttachmentTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores the files a run was given and records what could be read from them.
 *
 * Extraction happens here, before the model is called, so that a file nothing
 * could be read from is visible as such on the run from the moment it starts —
 * rather than being discovered later by a reviewer wondering why the draft
 * ignored the budget.
 */
class AttachFilesToRun
{
    public function __construct(
        protected AttachmentTextExtractor $extractor,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, TaskRunAttachment>
     */
    public function handle(TaskRun $run, array $files): array
    {
        $disk = (string) config('signoff.attachments.disk', 'local');
        $attachments = [];

        foreach ($files as $file) {
            // Read before storing: the temporary upload is the copy that is
            // certainly still there.
            $extraction = $this->extractor->extract($file);

            // Filed under the organization, so one team's uploads are never in
            // another's directory even if a path leaks.
            $path = $file->store('attachments/'.$run->team_id.'/'.$run->id, $disk);

            $attachments[] = TaskRunAttachment::create([
                'task_run_id' => $run->id,
                'team_id' => $run->team_id,
                // Kept for display only. The stored path is generated, so a
                // crafted filename cannot reach the filesystem.
                'original_name' => $file->getClientOriginalName(),
                'path' => (string) $path,
                'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'extraction' => $extraction['extraction'],
                'text' => $extraction['text'] !== '' ? $extraction['text'] : null,
                'text_chars' => mb_strlen($extraction['text']),
                'truncated' => $extraction['truncated'],
                'failure_reason' => $extraction['failure_reason'],
            ]);
        }

        return $attachments;
    }

    /**
     * A revision works from the same papers as the run it revises, without the
     * person having to upload them again.
     */
    public function copy(TaskRun $from, TaskRun $to): void
    {
        $disk = (string) config('signoff.attachments.disk', 'local');

        foreach ($from->attachments as $attachment) {
            $path = 'attachments/'.$to->team_id.'/'.$to->id.'/'.basename($attachment->path);

            if (Storage::disk($disk)->exists($attachment->path)) {
                Storage::disk($disk)->copy($attachment->path, $path);
            }

            $copy = $attachment->replicate(['task_run_id', 'path']);
            $copy->task_run_id = $to->id;
            $copy->path = $path;
            $copy->save();
        }
    }
}
