<?php

namespace App\Services;

use App\Models\TaskRun;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One driver for every provider that speaks OpenAI's chat-completions shape —
 * OpenAI, xAI, Mistral, Meta's Llama API, and Ollama, whether that is running
 * on a laptop or a private server.
 *
 * Adding another provider of this kind is an entry in config/ai.php and
 * nothing else. Deliberately plain HTTP rather than a vendor SDK, because the
 * whole point is that no vendor owns this path.
 */
class OpenAiCompatibleExecutor implements TaskExecutor
{
    public function __construct(
        protected TaskPromptBuilder $prompts,
        protected AiCredentials $credentials,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        $baseUrl = rtrim((string) $this->credentials->baseUrl, '/');

        if ($baseUrl === '') {
            throw new RuntimeException('No address is configured for this provider.');
        }

        $request = Http::timeout((int) config('ai.timeout', 180))
            ->acceptJson()
            ->asJson();

        // Ollama on a private machine usually has no key at all.
        if (filled($this->credentials->apiKey)) {
            $request = $request->withToken((string) $this->credentials->apiKey);
        }

        $response = $request->post($baseUrl.'/chat/completions', [
            'model' => $this->credentials->model,
            'max_tokens' => (int) config('ai.max_tokens', 16000),
            'messages' => $this->messages($run),
        ]);

        if (! $response->successful()) {
            // Surface the provider's own words: "insufficient quota" or "model
            // not found" is something the organization can act on, where
            // "request failed" is not.
            throw new RuntimeException($this->errorFrom($response->json(), $response->status()));
        }

        $output = trim((string) $response->json('choices.0.message.content', ''));

        if ($output === '') {
            throw new RuntimeException('The model returned no text.');
        }

        return new ExecutionResult(
            output: $output,
            model: (string) $response->json('model', $this->credentials->model),
            usage: [
                'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
                // Only Anthropic's native API reports cache reads. Recorded as
                // zero here rather than omitted, so a run's usage always has
                // the same shape.
                'cache_read_input_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
            ],
        );
    }

    /**
     * The instructions go in a system message and the organization's own
     * details in the user message, exactly as on the Anthropic path. Providers
     * that cache prefixes benefit from the same ordering.
     *
     * @return list<array<string, string>>
     */
    protected function messages(TaskRun $run): array
    {
        $system = collect($this->prompts->system($run))
            ->map(fn (array $block) => (string) $block['text'])
            ->implode("\n\n");

        return [
            ['role' => 'system', 'content' => $system],
            ...array_map(
                fn (array $message) => [
                    'role' => (string) $message['role'],
                    'content' => (string) $message['content'],
                ],
                $this->prompts->messages($run),
            ),
        ];
    }

    protected function errorFrom(mixed $body, int $status): string
    {
        $message = is_array($body)
            ? ($body['error']['message'] ?? $body['error'] ?? $body['message'] ?? null)
            : null;

        return is_string($message) && $message !== ''
            ? $message
            : "The provider rejected the request (HTTP {$status}).";
    }
}
