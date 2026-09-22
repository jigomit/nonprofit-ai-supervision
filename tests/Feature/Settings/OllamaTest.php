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

/**
 * @param  int  $window  What the model advertises, as /api/show reports it.
 * @param  int|null  $promptEvalCount  Tokens the server claims it read. Left
 *                                     null it reports a realistic full read of
 *                                     whatever was actually sent, at the 4.5
 *                                     characters per token measured on llama3.
 */
function fakeOllama(int $window = 8192, ?int $promptEvalCount = null, string $content = 'A local draft.'): void
{
    Http::fake([
        '*/api/show' => Http::response(['model_info' => ['llama.context_length' => $window]]),
        '*/api/chat' => function ($request) use ($promptEvalCount, $content) {
            $sent = array_sum(array_map(
                fn (array $message) => strlen($message['content']),
                $request->data()['messages'],
            ));

            return Http::response([
                'model' => 'llama3',
                'message' => ['role' => 'assistant', 'content' => $content],
                'prompt_eval_count' => $promptEvalCount ?? (int) round($sent / 4.5),
                'eval_count' => 400,
            ]);
        },
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

it('asks for a window this prompt needs, not the default and not the maximum', function () {
    // The window is allocated up front, so asking a 128k model for all of it
    // to draft one letter costs gigabytes for nothing.
    fakeOllama(window: 131072);

    app(TaskExecutorFactory::class)->execute(ollamaRun(4000));

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/chat')) {
            return false;
        }

        $options = $request->data()['options'];

        return $options['num_ctx'] > 4096
            && $options['num_ctx'] < 131072
            && $options['num_predict'] > 0;
    });
});

it('refuses a task the model has no room to write an answer to', function () {
    // A 60,000 character skill is about 15,000 tokens; llama3 holds 8,192.
    fakeOllama(window: 8192);

    expect(fn () => app(TaskExecutorFactory::class)->execute(ollamaRun(60000)))
        ->toThrow(RuntimeException::class, 'leaving no room to write');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/chat'));
});

it('runs a prompt that takes more than half the window', function () {
    // Ollama keeps a prompt whole as long as it fits. Refusing at half the
    // window would turn away most of this catalogue for no reason.
    fakeOllama(window: 8192, promptEvalCount: 5000, content: 'A long draft.');

    expect(app(TaskExecutorFactory::class)->execute(ollamaRun(22000))->output)
        ->toBe('A long draft.');
});

it('discards a draft written from trimmed instructions', function () {
    // 30,000 characters is about 7,500 tokens; reading 3,400 of them means
    // Ollama dropped the middle and said nothing.
    fakeOllama(window: 16384, promptEvalCount: 3400);

    expect(fn () => app(TaskExecutorFactory::class)->execute(ollamaRun(30000)))
        ->toThrow(RuntimeException::class, 'part of the task was dropped');
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
    // A prompt llama3 would refuse goes through on a larger model, with no
    // code change — the window comes from /api/show, not from a table here.
    fakeOllama(window: 32768, promptEvalCount: 7000);

    $result = app(TaskExecutorFactory::class)->execute(ollamaRun(30000));

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
