<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
});

/**
 * A profile complete enough that the setup chain has nothing left to say —
 * onboarded, a provider set, and enough context for the model to be specific.
 */
function readyProfile(): OrganizationProfile
{
    return OrganizationProfile::create([
        'team_id' => test()->team->id,
        'onboarded_at' => now(),
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-test',
        'mission' => 'Transitional housing for families in Akron.',
        'entity_type' => '501(c)(3) public charity',
        'budget_band' => '1m_to_5m',
        'state_of_incorporation' => 'OH',
    ]);
}

it('sends a brand new organization to set itself up', function () {
    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Tell us about your organization')
        ->click('Set up the organization')
        ->assertSee('Special collections')
        ->assertNoJavascriptErrors();
});

it('asks who writes the drafts before letting anyone run one', function () {
    // Without this step a task returns placeholder text and still reaches the
    // approval queue looking like work.
    OrganizationProfile::create(['team_id' => $this->team->id, 'onboarded_at' => now()]);
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Choose who writes the drafts')
        ->click('Set the AI provider')
        ->assertSee('AI provider')
        ->assertNoJavascriptErrors();
});

it('asks what the organization does before letting anyone run a task', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'onboarded_at' => now(),
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-test',
    ]);
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Say what your organization does')
        ->assertNoJavascriptErrors();
});

it('points a set-up organization at its first task', function () {
    readyProfile();
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Run your first task')
        ->click('Open the catalogue')
        ->assertSee('Skill catalogue')
        ->assertNoJavascriptErrors();
});

it('leads with the queue and opens what is waiting', function () {
    readyProfile();
    $skill = Skill::factory()->create(['name' => 'Annual appeal letter']);
    app(SyncTeamCatalogue::class)->handle($this->team);

    TaskRun::factory()->create([
        'team_id' => $this->team->id,
        'skill_id' => $skill->id,
        'requested_by' => $this->owner->id,
    ]);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Waiting for you')
        ->assertSee('Annual appeal letter')
        ->assertDontSee('Nothing is waiting on you')
        ->click('Annual appeal letter')
        ->assertSee('Your decision')
        ->assertNoJavascriptErrors();
});

it('says plainly when there is nothing to do', function () {
    readyProfile();
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);
    TaskRun::factory()->released()->create(['team_id' => $this->team->id]);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Nothing is waiting on you')
        ->assertNoJavascriptErrors();
});
