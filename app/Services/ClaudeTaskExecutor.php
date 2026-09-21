<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\MessageParam\Role;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use App\Models\TaskRun;
use RuntimeException;

class ClaudeTaskExecutor implements TaskExecutor
{
    public function __construct(
        protected TaskPromptBuilder $prompts,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        $apiKey = (string) config('claude.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('No Anthropic API key is configured.');
        }

        $client = new Client(apiKey: $apiKey);
        $model = (string) config('claude.model');

        $message = $client->messages->create(
            model: $model,
            maxTokens: (int) config('claude.max_tokens', 16000),
            system: $this->systemBlocks($run),
            messages: $this->messageParams($run),
        );

        // A refusal comes back as a successful response, so it has to be read
        // rather than caught. Surfacing it as a failure keeps a refusal out of
        // the approval queue, where it would look like reviewable work.
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The model declined to produce this output.');
        }

        $output = $this->text($message->content);

        if (trim($output) === '') {
            throw new RuntimeException('The model returned no text.');
        }

        return new ExecutionResult(
            output: $output,
            model: $message->model,
            usage: [
                'input_tokens' => $message->usage->inputTokens,
                'output_tokens' => $message->usage->outputTokens,
                'cache_creation_input_tokens' => $message->usage->cacheCreationInputTokens,
                'cache_read_input_tokens' => $message->usage->cacheReadInputTokens,
            ],
        );
    }

    /**
     * The builder stays provider-agnostic — a skill body is portable prompt
     * material — so the mapping into Anthropic's own param objects happens
     * here, where the SDK is already a dependency.
     *
     * @return list<TextBlockParam>
     */
    protected function systemBlocks(TaskRun $run): array
    {
        $blocks = [];

        foreach ($this->prompts->system($run) as $block) {
            $cacheControl = isset($block['cacheControl'])
                ? CacheControlEphemeral::with(ttl: $block['cacheControl']['ttl'] ?? null)
                : null;

            $blocks[] = TextBlockParam::with(
                text: (string) $block['text'],
                cacheControl: $cacheControl,
            );
        }

        return $blocks;
    }

    /**
     * @return list<MessageParam>
     */
    protected function messageParams(TaskRun $run): array
    {
        $messages = [];

        foreach ($this->prompts->messages($run) as $message) {
            $messages[] = MessageParam::with(
                content: (string) $message['content'],
                role: Role::from((string) $message['role']),
            );
        }

        return $messages;
    }

    /**
     * @param  array<int, mixed>  $content
     */
    protected function text(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextBlock) {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n", $parts));
    }
}
