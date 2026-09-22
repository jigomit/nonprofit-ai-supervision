<?php

use App\Actions\SyncTeamCatalogue;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
});

it('sends a brand new organization to set itself up', function () {
    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Tell us about your organization')
        ->click('Set up the organization')
        ->assertSee('Special collections')
        ->assertNoJavascriptErrors();
});

it('points a set-up organization at its first task', function () {
    OrganizationProfile::create(['team_id' => $this->team->id, 'onboarded_at' => now()]);
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Run your first task')
        ->click('Open the catalogue')
        ->assertSee('Skill catalogue')
        ->assertNoJavascriptErrors();
});

it('leads with the queue and opens what is waiting', function () {
    OrganizationProfile::create(['team_id' => $this->team->id, 'onboarded_at' => now()]);
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
    OrganizationProfile::create(['team_id' => $this->team->id, 'onboarded_at' => now()]);
    Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);
    TaskRun::factory()->released()->create(['team_id' => $this->team->id]);

    visit('/'.$this->team->slug.'/dashboard')
        ->assertSee('Nothing is waiting on you')
        ->assertNoJavascriptErrors();
});
