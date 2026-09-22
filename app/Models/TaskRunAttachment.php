<?php

namespace App\Models;

use App\Enums\AttachmentExtraction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file an organization gave a task to work from — last year's 990, the
 * budget, an export from the CRM.
 *
 * The file itself is kept on a private disk and never served from the web
 * root. What the model sees is the text pulled out of it, which is why that
 * text is stored alongside: the audit record should be able to show what the
 * draft was actually written from, not merely which file was attached.
 *
 * @property int $id
 * @property int $task_run_id
 * @property int $team_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property AttachmentExtraction $extraction
 * @property string|null $text
 * @property int $text_chars
 * @property bool $truncated
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property-read TaskRun $taskRun
 */
#[Fillable([
    'task_run_id', 'team_id', 'original_name', 'path', 'mime_type', 'size_bytes',
    'extraction', 'text', 'text_chars', 'truncated', 'failure_reason',
])]
class TaskRunAttachment extends Model
{
    /**
     * @return BelongsTo<TaskRun, $this>
     */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    public function delete(): ?bool
    {
        Storage::disk($this->disk())->delete($this->path);

        return parent::delete();
    }

    public function disk(): string
    {
        return (string) config('signoff.attachments.disk', 'local');
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $size < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extraction' => AttachmentExtraction::class,
            'size_bytes' => 'integer',
            'text_chars' => 'integer',
            'truncated' => 'boolean',
        ];
    }
}
