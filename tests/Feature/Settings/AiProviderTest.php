<?php

use App\Enums\AiProvider;
use App\Enums\TeamRole;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\AiCredentials;
use App\Services\AnthropicExecutor;
use App\Services\OpenAiCompatibleExecutor;
use App\Services\PlaceholderTaskExecutor;
use App\Services\TaskExecutorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->url = '/'.$this->team->slug.'/organization/ai';

    // The fallback is development-only; tests assert real per-org behaviour.
    config(['ai.fallback.provider' => null, 'ai.fallback.api_key' => null]);
});

it('never sends the stored key back to the browser', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-secret-value',
    ]);

    $response = $this->actingAs($this->user)->get($this->url);

    $response->assertOk();
    $response->assertDontSee('sk-secret-value');
    $response->assertInertia(fn ($page) => $page
        ->component('organization/Ai')
        ->where('settings.hasApiKey', true)
        ->where('settings.isConfigured', true)
        ->missing('settings.apiKey')
    );
});

it('encrypts the key at rest', function () {
    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-secret-value',
    ])->assertRedirect();

    $stored = DB::table('organization_profiles')->where('team_id', $this->team->id)->value('ai_api_key');

    expect($stored)->not->toContain('sk-secret-value')
        ->and($this->team->organizationProfile->ai_api_key)->toBe('sk-secret-value');
});

it('keeps the stored key when the field is left blank', function () {
    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-first',
    ])->assertRedirect();

    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'openai',
        'ai_model' => 'gpt-5-mini',
        'ai_api_key' => '',
    ])->assertRedirect();

    $profile = $this->team->organizationProfile()->first();

    expect($profile->ai_api_key)->toBe('sk-first')
        ->and($profile->ai_model)->toBe('gpt-5-mini');
});

it('will not let a provider be saved without the key it needs', function () {
    $this->actingAs($this->user)
        ->put($this->url, ['ai_provider' => 'xai'])
        ->assertSessionHasErrors('ai_api_key');

    expect($this->team->organizationProfile()->first())->toBeNull();
});

it('drops a key that belongs to the previous provider', function () {
    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-openai',
    ])->assertRedirect();

    // Ollama needs no key, so this save succeeds — the stale OpenAI key must
    // not survive it.
    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'ollama',
        'ai_base_url' => 'http://localhost:11434/v1',
    ])->assertRedirect();

    expect($this->team->organizationProfile()->first()->ai_api_key)->toBeNull();
});

it('accepts ollama on an address with no key at all', function () {
    $this->actingAs($this->user)->put($this->url, [
        'ai_provider' => 'ollama',
        'ai_base_url' => 'http://192.168.1.40:11434/v1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $profile = $this->team->organizationProfile()->first();

    expect($profile->hasAiConfigured())->toBeTrue()
        ->and($profile->resolvedBaseUrl())->toBe('http://192.168.1.40:11434/v1')
        ->and($profile->resolvedModel())->toBe(config('ai.providers.ollama.model'));
});

it('lets an owner remove the provider entirely', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::Anthropic,
        'ai_api_key' => 'sk-ant-something',
    ]);

    $this->actingAs($this->user)->delete($this->url)->assertRedirect();

    $profile = $this->team->organizationProfile()->first();

    expect($profile->ai_provider)->toBeNull()
        ->and($profile->ai_api_key)->toBeNull()
        ->and($profile->hasAiConfigured())->toBeFalse();
});

it('refuses a member who cannot change team settings', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)->put($this->url, [
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-not-allowed',
    ])->assertForbidden();

    expect($this->team->organizationProfile()->first())->toBeNull();
});

it('keeps one organization key away from another organization', function () {
    $other = User::factory()->create();

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-ours',
    ]);

    $theirs = AiCredentials::forTeam($other->personalTeam());
    $ours = AiCredentials::forTeam($this->team);

    expect($theirs)->toBeNull()
        ->and($ours->apiKey)->toBe('sk-ours');
});

it('picks the driver from the organization', function (?string $provider, string $expected) {
    if ($provider !== null) {
        OrganizationProfile::create([
            'team_id' => $this->team->id,
            'ai_provider' => AiProvider::from($provider),
            'ai_api_key' => 'sk-test',
            'ai_base_url' => 'http://localhost:11434/v1',
        ]);
    }

    $run = TaskRun::factory()->for($this->team)->for(Skill::factory())->create();

    expect(app(TaskExecutorFactory::class)->driverFor($run))->toBeInstanceOf($expected);
})->with([
    'anthropic' => ['anthropic', AnthropicExecutor::class],
    'openai' => ['openai', OpenAiCompatibleExecutor::class],
    'xai' => ['xai', OpenAiCompatibleExecutor::class],
    'mistral' => ['mistral', OpenAiCompatibleExecutor::class],
    'meta' => ['meta', OpenAiCompatibleExecutor::class],
    'ollama' => ['ollama', OpenAiCompatibleExecutor::class],
    'nothing configured' => [null, PlaceholderTaskExecutor::class],
]);

it('sends the skill body and the organization context to an openai-compatible provider', function () {
    Http::fake([
        'api.x.ai/*' => Http::response([
            'model' => 'grok-4',
            'choices' => [['message' => ['content' => 'A draft.']]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ]),
    ]);

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::XAi,
        'ai_api_key' => 'sk-xai-key',
        'mission' => 'Housing support for families in Akron.',
    ]);

    $skill = Skill::factory()->create(['body' => 'Follow these instructions exactly.']);
    $run = TaskRun::factory()->for($this->team)->for($skill)->create();

    $result = app(TaskExecutorFactory::class)->execute($run);

    expect($result->output)->toBe('A draft.')
        ->and($result->model)->toBe('grok-4')
        ->and($result->usage['input_tokens'])->toBe(120);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-xai-key')
            && $body['model'] === 'grok-4'
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][0]['content'] === 'Follow these instructions exactly.'
            && str_contains($body['messages'][1]['content'], 'Housing support for families in Akron.');
    });
});

it('sends no bearer token to a local ollama', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'choices' => [['message' => ['content' => 'A local draft.']]],
        ]),
    ]);

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::Ollama,
        'ai_model' => 'llama3',
    ]);

    $run = TaskRun::factory()->for($this->team)->for(Skill::factory())->create();

    expect(app(TaskExecutorFactory::class)->execute($run)->output)->toBe('A local draft.');

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('repeats what the provider said when a request is refused', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => ['message' => 'You exceeded your current quota.'],
        ], 429),
    ]);

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-spent',
    ]);

    $run = TaskRun::factory()->for($this->team)->for(Skill::factory())->create();

    expect(fn () => app(TaskExecutorFactory::class)->execute($run))
        ->toThrow(RuntimeException::class, 'You exceeded your current quota.');
});
