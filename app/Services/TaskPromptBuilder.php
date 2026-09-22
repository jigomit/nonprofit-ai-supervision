<?php

namespace App\Services;

use App\Models\OrganizationProfile;
use App\Models\TaskRun;

/**
 * Assembles the request for one run.
 *
 * The one rule that matters here: the system block holds the skill body and
 * nothing else that varies. Prompt caching is a prefix match, so a single
 * interpolated organization name would make every request a cache miss and
 * multiply the bill roughly tenfold, silently. Organization context and the
 * user's inputs go in the message, after the cache breakpoint.
 */
class TaskPromptBuilder
{
    private const MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * The cacheable system block: the task's instructions, verbatim.
     *
     * @return list<array<string, mixed>>
     */
    public function system(TaskRun $run): array
    {
        return [
            [
                'type' => 'text',
                'text' => $run->skill->body,
                'cacheControl' => [
                    'type' => 'ephemeral',
                    'ttl' => (string) config('ai.cache_ttl', '1h'),
                ],
            ],
        ];
    }

    /**
     * The volatile half: who this is for and what they asked for.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(TaskRun $run): array
    {
        return [[
            'role' => 'user',
            'content' => $this->userContent($run),
        ]];
    }

    public function userContent(TaskRun $run): string
    {
        $sections = ["## Organization\n".$this->organizationContext($run)];

        $inputs = $this->formatInputs($run);

        if ($inputs !== '') {
            $sections[] = "## What was asked for\n".$inputs;
        }

        // Stated on every request rather than assumed. The model's output is
        // going to a person who has to decide whether to release it, and the
        // level it carries governs how hard they have to look.
        $sections[] = "## How this output will be handled\n".sprintf(
            "This draft is not final. It goes to a human gate before it can be used: %s\n".
            'Write it so a reviewer can check it. Where a fact depends on the organization '.
            'and you were not given it, say so explicitly instead of inventing it.',
            $run->supervision_at_run->gate(),
        );

        return implode("\n\n", $sections);
    }

    protected function organizationContext(TaskRun $run): string
    {
        $profile = $run->team->organizationProfile;

        $lines = ['Name: '.$run->team->name];

        if ($profile instanceof OrganizationProfile) {
            $facts = array_filter([
                'Entity type' => $profile->entity_type,
                'State of incorporation' => $profile->state_of_incorporation,
                'EIN' => $profile->ein,
                'Fiscal year ends' => self::MONTHS[$profile->fiscal_year_end_month] ?? null,
                'Annual budget' => $profile->budget_band?->label(),
                'Mission' => $profile->mission,
            ]);

            foreach ($facts as $label => $value) {
                $lines[] = "{$label}: {$value}";
            }
        }

        if (count($lines) === 1) {
            $lines[] = 'No further details recorded. Ask for anything you need rather than assuming it.';
        }

        return implode("\n", $lines);
    }

    protected function formatInputs(TaskRun $run): string
    {
        $lines = [];

        foreach ((array) ($run->inputs ?? []) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.$value;
            }
        }

        return implode("\n", $lines);
    }
}
