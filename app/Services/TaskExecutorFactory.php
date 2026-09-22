<?php

namespace App\Services;

use App\Models\TaskRun;

/**
 * Chooses the driver for a run from the organization that owns it.
 *
 * The provider is a per-team decision, not an application-wide one, so it
 * cannot be resolved at boot. This is itself a TaskExecutor so that everything
 * upstream — the job, the gates, the tests — keeps talking to one interface
 * and never learns which provider produced a draft.
 */
class TaskExecutorFactory implements TaskExecutor
{
    public function __construct(
        protected TaskPromptBuilder $prompts,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        return $this->driverFor($run)->execute($run);
    }

    public function driverFor(TaskRun $run): TaskExecutor
    {
        $credentials = AiCredentials::forTeam($run->team);

        // No provider configured: the run still completes and still lands in
        // its gate, with output that says plainly why it is not real work.
        if ($credentials === null) {
            return new PlaceholderTaskExecutor($this->prompts);
        }

        return $credentials->provider->isOpenAiCompatible()
            ? new OpenAiCompatibleExecutor($this->prompts, $credentials)
            : new AnthropicExecutor($this->prompts, $credentials);
    }
}
