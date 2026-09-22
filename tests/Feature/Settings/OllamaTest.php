<?php

use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\OllamaExecutor;
use App\Services\TaskExecutorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Local models are the case where a wrong answer is cheapest to produce and
 * hardest to notice: over its context window Ollama trims the prompt and
 * answers from what is left, with no error anywhere. These assert the trimmed
 * draft never reaches a reviewer.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();

    config(['ai.fallback.provider' => null, 'ai.fallback.api_key' => null]);

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::Ollama,
        'ai_model' => 'llama3',
        'ai_base_url' => 'http://localhost:11434/v1',
    ]);
});

/** @param  int  $window  What the model advertises, as /api/show reports it. */
function fakeOllama(int $window = 8192, ?int $promptEvalCount = null, string $content = 'A local draft.'): void
{
    Http::fake([
        '*/api/show' => Http::response(['model_info' => ['llama.context_length' => $window]]),
        '*/api/chat' => Http::response([
            'model' => 'llama3',
            'message' => ['role' => 'assistant', 'content' => $content],
            'prompt_eval_count' => $promptEvalCount ?? 100,
            'eval_count' => 400,
        ]),
    ]);
}

function ollamaRun(int $bodyLength = 3500): TaskRun
{
    return TaskRun::factory()
        ->for(test()->team)
        ->for(Skill::factory()->create(['body' => str_repeat('a', $bodyLength)]))
        ->create();
}

it('uses the native endpoint, because the openai one cannot set the window', function () {
    fakeOllama();

    app(TaskExecutorFactory::class)->execute(ollamaRun());

    Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/api/chat');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
});

it('is chosen over the shared openai driver', function () {
    fakeOllama();

    expect(app(TaskExecutorFactory::class)->driverFor(ollamaRun()))
        ->toBeInstanceOf(OllamaExecutor::class);
});

it('sizes the window to the prompt rather than leaving it at the default', function () {
    fakeOllama();

    app(TaskExecutorFactory::class)->execute(ollamaRun(3500));

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/chat')) {
            return false;
        }

        $options = $request->data()['options'];

        // Ollama keeps only half the window for the prompt, so the window has
        // to be at least twice what is being sent.
        return $options['num_ctx'] >= 2 * 1000
            && $options['num_ctx'] <= 8192
            && $options['num_predict'] > 0;
    });
});

it('refuses a task the model is too small to be given', function () {
    // A 60,000 character skill is about 17,000 tokens; llama3 can be given
    // 4,096 of them.
    fakeOllama(window: 8192);

    expect(fn () => app(TaskExecutorFactory::class)->execute(ollamaRun(60000)))
        ->toThrow(RuntimeException::class, 'can only be given 4,096');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/chat'));
});

it('discards a draft written from trimmed instructions', function () {
    // The window is sized to the prompt, so a read that reaches half of it
    // means Ollama trimmed rather than refused.
    fakeOllama(window: 8192, promptEvalCount: 4096);

    expect(fn () => app(TaskExecutorFactory::class)->execute(ollamaRun(7000)))
        ->toThrow(RuntimeException::class, 'trimmed to fit');
});

it('lets a draft through when the whole prompt was read', function () {
    fakeOllama(window: 8192, promptEvalCount: 900, content: 'The finished draft.');

    $result = app(TaskExecutorFactory::class)->execute(ollamaRun(3500));

    expect($result->output)->toBe('The finished draft.')
        ->and($result->model)->toBe('llama3')
        ->and($result->usage['input_tokens'])->toBe(900)
        ->and($result->usage['cache_read_input_tokens'])->toBe(0);
});

it('asks the server what the model holds instead of assuming', function () {
    // A 32k model can be given 16k, so a prompt that llama3 would refuse goes
    // through without a code change.
    fakeOllama(window: 32768, promptEvalCount: 5000);

    $result = app(TaskExecutorFactory::class)->execute(ollamaRun(20000));

    expect($result->output)->toBe('A local draft.');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/show'));
});

it('says so plainly when the server is not running', function () {
    Http::fake(['*/api/show' => Http::response(['error' => 'model not found'], 404)]);

    expect(fn () => app(TaskExecutorFactory::class)->execute(ollamaRun()))
        ->toThrow(RuntimeException::class, 'has no model called llama3');
});

it('sends no bearer token to a machine on the local network', function () {
    fakeOllama();

    app(TaskExecutorFactory::class)->execute(ollamaRun());

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});
