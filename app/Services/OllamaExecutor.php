<?php

namespace App\Services;

use App\Models\TaskRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ollama, on the organization's own machine.
 *
 * This does not go through the OpenAI-compatible endpoint, for one reason that
 * matters more than the convenience of sharing a driver: that endpoint gives
 * no way to set the context window, and Ollama's default is 4,096 tokens.
 *
 * Skill bodies in this catalogue average about 3,900 tokens and reach 9,400.
 * Over the window, Ollama does not fail — it silently drops the middle of the
 * prompt and answers from what is left. Measured on llama3 here: a 40,000
 * character skill was cut to 2,060 tokens, and the model then answered a
 * question about the instructions with a heading it had invented.
 *
 * A draft produced from half its instructions, arriving in a review queue
 * looking like ordinary work, is the exact failure this application exists to
 * prevent. So this driver sizes the window to the prompt, refuses the run when
 * the model is too small to hold it, and checks afterwards that the whole
 * prompt was actually read.
 */
class OllamaExecutor implements TaskExecutor
{
    /**
     * Characters per token, deliberately low. Measured around 4.3 on this
     * corpus, so 3.5 over-estimates the prompt by roughly a fifth — the margin
     * is on the safe side, because under-estimating means silent truncation.
     */
    protected const CHARS_PER_TOKEN = 3.5;

    /** A draft shorter than this is not worth the gate it would occupy. */
    protected const MIN_OUTPUT_TOKENS = 1024;

    /**
     * Ollama keeps only half the context window for the prompt and reserves
     * the rest for generation. Measured exactly on this machine: num_ctx 2048,
     * 4096 and 8192 read 1,036 / 2,060 / 4,108 tokens of a prompt far longer
     * than any of them. So a prompt of P tokens needs a window of 2P, and the
     * model's own limit has to be read as half of what it advertises.
     */
    protected const PROMPT_SHARE = 2;

    public function __construct(
        protected TaskPromptBuilder $prompts,
        protected AiCredentials $credentials,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        $messages = $this->messages($run);
        $promptTokens = $this->estimateTokens($messages);
        $window = $this->contextWindow();

        // What the model can actually be given, as opposed to what it claims.
        $promptBudget = intdiv($window, self::PROMPT_SHARE);

        if ($promptTokens > $promptBudget) {
            throw new RuntimeException(sprintf(
                'This task needs about %s tokens of instructions. %s advertises a %s token '.
                'window but keeps half of it for writing, so it can only be given %s — the rest '.
                'would be dropped without warning. Pull a model with a larger window '.
                '(llama3.1 and later hold far more) or run this task on a hosted provider.',
                number_format($promptTokens),
                $this->credentials->model,
                number_format($window),
                number_format($promptBudget),
            ));
        }

        // Sized so the prompt sits inside its half with room to spare, rather
        // than at the edge where Ollama would start trimming.
        $contextWindow = min($window, max(
            self::PROMPT_SHARE * $promptTokens,
            $promptTokens + self::MIN_OUTPUT_TOKENS,
        ));

        $outputTokens = min(
            (int) config('ai.max_tokens', 16000),
            $contextWindow - $promptTokens,
        );

        $response = $this->request()->post($this->url('/api/chat'), [
            'model' => $this->credentials->model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                // Sized to this prompt rather than left at the default, which
                // is the whole point of using the native endpoint.
                'num_ctx' => $contextWindow,
                'num_predict' => $outputTokens,
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorFrom($response->json(), $response->status()));
        }

        $read = (int) $response->json('prompt_eval_count', 0);

        // Ollama reports what it actually read, and its trimming stops exactly
        // at half the window. Reaching that line means the prompt was cut, so
        // whatever came back was written without part of the task. Refuse it
        // rather than let it reach a reviewer looking like ordinary work.
        if ($read > 0 && $read >= intdiv($contextWindow, self::PROMPT_SHARE)) {
            throw new RuntimeException(sprintf(
                'The instructions were trimmed to fit: %s tokens reached the model out of '.
                'about %s. The draft would have been written from part of the task, so it '.
                'has been discarded. Use a model with a larger context window.',
                number_format($read),
                number_format($promptTokens),
            ));
        }

        $output = trim((string) $response->json('message.content', ''));

        if ($output === '') {
            throw new RuntimeException('The model returned no text.');
        }

        return new ExecutionResult(
            output: $output,
            model: (string) $response->json('model', $this->credentials->model),
            usage: [
                'input_tokens' => $read,
                'output_tokens' => (int) $response->json('eval_count', 0),
                // Nothing local is cached between requests, and recording zero
                // rather than omitting it keeps usage the same shape everywhere.
                'cache_read_input_tokens' => 0,
                'context_window' => $contextWindow,
            ],
        );
    }

    /**
     * What this model can actually hold, asked of the running server rather
     * than assumed. Cached because it is a property of the model file.
     */
    protected function contextWindow(): int
    {
        $model = $this->credentials->model;

        return Cache::remember(
            'ollama.context.'.md5($this->url('').$model),
            now()->addHour(),
            function () use ($model): int {
                $response = $this->request()->post($this->url('/api/show'), ['model' => $model]);

                if (! $response->successful()) {
                    throw new RuntimeException(sprintf(
                        'Could not reach Ollama at %s, or it has no model called %s.',
                        $this->url(''),
                        $model,
                    ));
                }

                foreach ((array) $response->json('model_info', []) as $key => $value) {
                    if (str_ends_with((string) $key, '.context_length') && is_int($value)) {
                        return $value;
                    }
                }

                // A model that does not declare its window gets Ollama's own
                // default rather than an optimistic guess.
                return 4096;
            },
        );
    }

    /**
     * @param  list<array<string, string>>  $messages
     */
    protected function estimateTokens(array $messages): int
    {
        $characters = array_sum(array_map(
            fn (array $message) => mb_strlen($message['content']),
            $messages,
        ));

        return (int) ceil($characters / self::CHARS_PER_TOKEN);
    }

    /**
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

    protected function request(): PendingRequest
    {
        $request = Http::timeout((int) config('ai.ollama_timeout', 900))
            ->acceptJson()
            ->asJson();

        // Usually none, but a shared or tunnelled server may sit behind one.
        return filled($this->credentials->apiKey)
            ? $request->withToken((string) $this->credentials->apiKey)
            : $request;
    }

    /**
     * The stored address points at the OpenAI-compatible path, because that is
     * what every other provider uses and what an organization pastes in. The
     * native endpoints sit one level up.
     */
    protected function url(string $path): string
    {
        $base = rtrim((string) $this->credentials->baseUrl, '/');

        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        return rtrim($base, '/').$path;
    }

    protected function errorFrom(mixed $body, int $status): string
    {
        $message = is_array($body) ? ($body['error'] ?? null) : null;

        return is_string($message) && $message !== ''
            ? $message
            : "Ollama refused the request (HTTP {$status}).";
    }
}
