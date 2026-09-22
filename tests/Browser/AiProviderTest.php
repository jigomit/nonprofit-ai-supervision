<?php

use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\User;

/**
 * The page holds a secret, so the thing worth proving in a real browser is
 * what it does not put on screen.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
});

it('says plainly that no provider is set', function () {
    $page = visit('/'.$this->team->slug.'/organization/ai');

    $page->assertSee('No provider is set')
        ->assertSee('Anthropic (Claude)')
        ->assertSee('OpenAI (ChatGPT)')
        ->assertSee('Ollama')
        ->assertNoJavascriptErrors();
});

it('shows a stored key as stored, never as text', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-should-never-appear',
    ]);

    $page = visit('/'.$this->team->slug.'/organization/ai');

    $page->assertSee('Drafts are being produced by')
        ->assertDontSee('sk-should-never-appear')
        ->assertNoJavascriptErrors();
});

it('saves a provider and reports it back', function () {
    $page = visit('/'.$this->team->slug.'/organization/ai');

    $page->click('Ollama')
        ->fill('ai_base_url', 'http://localhost:11434/v1')
        ->click('Save provider')
        ->assertSee('Drafts are being produced by')
        ->assertNoJavascriptErrors();

    expect($this->team->organizationProfile()->first()->ai_provider)->toBe(AiProvider::Ollama);
});
