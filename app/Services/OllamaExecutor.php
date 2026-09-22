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
 * no way to set the context window, and Ollama's default is 4,096 tokens —
 * under half of what the average task in this catalogue needs.
 *
 * Over the window Ollama does not fail. It drops the middle of the prompt and
 * answers from what is left, reporting success. Measured here on a 40,328
 * character skill: at num_ctx 8,192 it read 4,098 of 8,648 tokens and still
 * answered a question about the last heading correctly, because the trimming
 * takes the middle and leaves both ends. Nothing in the response says a word
 * of it.
 *
 * A draft written from the outer edges of its instructions, arriving in a
 * review queue looking like ordinary work, is the exact failure this
 * application exists to prevent. So this driver sizes the window to the
 * prompt, refuses when the model cannot hold it, and checks afterwards that
 * what the server read matches what was sent.
 */
class OllamaExecutor implements TaskExecutor
{
    /**
     * Characters per token. Measured between 4.28 and 4.51 across this corpus,
     * so 4.0 over-estimates slightly — the margin belongs on this side,
     * because under-estimating is what lets a prompt be trimmed in silence.
     */
    protected const CHARS_PER_TOKEN = 4.0;

    /** A draft shorter than this is not worth the gate it would occupy. */
    protected const MIN_OUTPUT_TOKENS = 1024;

    /**
     * How much of the estimate must come back as actually read. A whole prompt
     * reports 0.88 to 0.94 of the estimate above; a trimmed one reports around
     * 0.45, because Ollama halves what will not fit. Anything under this is
     * trimming, not estimation error.
     */
    protected const MIN_READ_RATIO = 0.75;

    public function __construct(
        protected TaskPromptBuilder $prompts,
        protected AiCredentials $credentials,
    ) {}

    public function execute(TaskRun $run): ExecutionResult
    {
        $messages = $this->messages($run);
        $promptTokens = $this->estimateTokens($messages);
        $window = $this->contextWindow();

        if ($promptTokens + self::MIN_OUTPUT_TOKENS > $window) {
            throw new RuntimeException(sprintf(
                'This task needs about %s tokens of instructions and %s can hold %s, leaving '.
                'no room to write. Over that line Ollama drops the middle of the instructions '.
                'without saying so, which is not something a draft should be built on. Pull a '.
                'model with a larger context window (llama3.1 and later hold far more) or run '.
                'this task on a hosted provider.',
                number_format($promptTokens),
                $this->credentials->model,
                number_format($window),
            ));
        }

        // Asked for per request, rather than left at Ollama's 4,096 default.
        // Only as large as this prompt needs: the window is allocated up
        // front, so claiming a 128k model's full window would cost gigabytes
        // of memory to draft one letter.
        $outputTokens = min(
            (int) config('ai.max_tokens', 16000),
            $window - $promptTokens,
        );

        $contextWindow = $promptTokens + $outputTokens;

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

        // Ollama reports what it actually read. Well short of what was sent is
        // the only signal that the middle was dropped, so it is checked rather
        // than trusted — a draft written from part of its instructions must
        // not reach a reviewer looking like ordinary work.
        if ($read > 0 && $read < $promptTokens * self::MIN_READ_RATIO) {
            throw new RuntimeException(sprintf(
                'Only %s of about %s tokens of instructions reached the model, so part of the '.
                'task was dropped and the draft has been discarded. Use a model with a larger '.
                'context window.',
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
