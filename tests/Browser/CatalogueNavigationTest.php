<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\SupervisionLevel;
use App\Models\Skill;
use App\Models\User;

/**
 * These click their way through the app rather than visiting URLs directly.
 *
 * That distinction is the point. Every other test in this suite asserts the
 * server returns the right props; none of them render a page, so a link that
 * goes nowhere is invisible to all of them.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->actingAs($this->user);
});

function enableSkill(mixed $team, array $attributes = []): Skill
{
    $skill = Skill::factory()->create($attributes);
    app(SyncTeamCatalogue::class)->handle($team);

    return $skill;
}

it('opens a task by clicking its card in the catalogue', function () {
    $skill = enableSkill($this->team, [
        'name' => 'Form 990 preparation',
        'supervision' => SupervisionLevel::ExpertRequired->value,
        'supervision_note' => 'Filed with the IRS.',
    ]);

    visit('/'.$this->team->slug.'/skills')
        ->assertSee('Form 990 preparation')
        ->click('Form 990 preparation')
        ->assertUrlIs(config('app.url').'/'.$this->team->slug.'/skills/'.$skill->slug)
        ->assertSee('Filed with the IRS.')
        ->assertNoJavascriptErrors();
});

it('gets back to the catalogue from a task', function () {
    enableSkill($this->team, ['name' => 'Donor pipeline']);

    visit('/'.$this->team->slug.'/skills')
        ->click('Donor pipeline')
        ->click('Back to catalogue')
        ->assertUrlIs(config('app.url').'/'.$this->team->slug.'/skills')
        ->assertSee('Skill catalogue')
        ->assertNoJavascriptErrors();
});

it('reaches every section from the sidebar', function () {
    enableSkill($this->team);

    $page = visit('/'.$this->team->slug.'/dashboard');

    foreach (['Work', 'Calendar', 'Skill catalogue', 'Board report', 'Organization'] as $section) {
        $page->click($section)->assertNoJavascriptErrors();
    }
});

it('works when the catalogue is reached with a trailing slash', function () {
    enableSkill($this->team, ['name' => 'Coalition building']);

    visit('/'.$this->team->slug.'/skills/')
        ->click('Coalition building')
        ->assertSee('Coalition building')
        ->assertNoJavascriptErrors();
});

it('filters the catalogue by supervision level', function () {
    enableSkill($this->team, [
        'name' => 'Social media posts',
        'supervision' => SupervisionLevel::Unsupervised->value,
    ]);
    enableSkill($this->team, [
        'name' => 'Bylaws review',
        'supervision' => SupervisionLevel::ExpertRequired->value,
    ]);

    visit('/'.$this->team->slug.'/skills')
        ->assertSee('Social media posts')
        ->assertSee('Bylaws review')
        ->click('Expert required')
        ->assertSee('Bylaws review')
        ->assertDontSee('Social media posts')
        ->assertNoJavascriptErrors();
});
