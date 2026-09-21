<?php

namespace App\Jobs;

use App\Enums\TaskRunStatus;
use App\Models\TaskRun;
use App\Services\TaskExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public TaskRun $taskRun,
    ) {}

    public function handle(TaskExecutor $executor): void
    {
        $run = $this->taskRun->fresh();

        // A run that already reached a gate must not be re-executed by a retry:
        // that would discard output someone may already be reviewing.
        if ($run === null || $run->status !== TaskRunStatus::Queued) {
            return;
        }

        $run->forceFill([
            'status' => TaskRunStatus::Running,
            'started_at' => now(),
        ])->save();

        try {
            $result = $executor->execute($run);
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        $run->forceFill([
            // The level snapshotted when the run was created decides which gate
            // this lands in — not whatever the library says by the time the
            // model finishes.
            'status' => TaskRunStatus::gateFor($run->supervision_at_run),
            'output' => $result->output,
            'model' => $result->model,
            'usage' => $result->usage,
            'completed_at' => now(),
            'released_at' => $run->supervision_at_run->releasesAutomatically() ? now() : null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->taskRun->forceFill([
            'status' => TaskRunStatus::Failed,
            'failure_reason' => $exception?->getMessage(),
            'completed_at' => now(),
        ])->save();
    }
}
