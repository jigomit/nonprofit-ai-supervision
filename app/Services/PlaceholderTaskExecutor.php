<?php

namespace App\Services;

use App\Models\TaskRun;

/**
 * Stands in when no API key is configured.
 *
 * The point is that the gates, the approval queue and the audit record can be
 * demonstrated end to end without spending anything — and that a missing key
 * fails visibly in the output rather than silently producing nothing.
 */
class PlaceholderTaskExecutor implements TaskExecutor
{
    public function __construct(
        protected TaskPromptBuilder $prompts,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        $output = implode("\n\n", [
            '> **No AI provider is configured.** This is placeholder output, so the review and approval steps can still be exercised. Choose a provider under Organization → AI provider to produce real drafts.',
            '## '.$run->skill->name,
            $run->skill->description,
            '---',
            '### Context this run was given',
            '```',
            $this->prompts->userContent($run),
            '```',
        ]);

        return new ExecutionResult(
            output: $output,
            model: 'placeholder',
            usage: [],
        );
    }
}
