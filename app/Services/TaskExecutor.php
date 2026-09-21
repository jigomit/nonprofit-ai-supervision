<?php

namespace App\Services;

use App\Models\TaskRun;

interface TaskExecutor
{
    /**
     * Produce a draft for this run. Implementations throw on failure; the job
     * is what decides how a failure is recorded.
     */
    public function execute(TaskRun $run): ExecutionResult;
}
