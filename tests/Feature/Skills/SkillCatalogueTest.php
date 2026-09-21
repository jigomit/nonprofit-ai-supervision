<?php

use App\Models\Skill;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The user factory already creates and switches to a personal team.
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
});

function catalogueUrl(Team $team, array $query = []): string
{
    return '/'.$team->slug.'/skills'.($query === [] ? '' : '?'.http_build_query($query));
}

it('requires authentication', function () {
    $this->get(catalogueUrl($this->team))->assertRedirect('/login');
});

it('lists the catalogue with its supervision breakdown', function () {
    Skill::factory()->count(3)->create();
    Skill::factory()->unsupervised()->create();
    Skill::factory()->expertRequired()->create();

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('skills/Index')
            ->has('skills', 5)
            ->has('supervisionLevels', 3)
            ->where('supervisionLevels.0.value', 'unsupervised')
            ->where('supervisionLevels.0.total', 1)
            ->where('supervisionLevels.1.total', 3)
            ->where('supervisionLevels.2.total', 1)
        );
});

it('filters by supervision level', function () {
    Skill::factory()->count(2)->create();
    $expert = Skill::factory()->expertRequired()->create();

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['supervision' => 'expert-required']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('skills', 1)
            ->where('skills.0.slug', $expert->slug)
            ->where('filters.supervision', 'expert-required')
        );
});

it('ignores a supervision filter that is not a real level', function () {
    Skill::factory()->count(3)->create();

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['supervision' => 'autonomous']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('skills', 3));
});

it('filters by collection', function () {
    Skill::factory()->count(2)->create();
    $special = Skill::factory()->specialCollection()->create();

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['collection' => 'special']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('skills', 1)
            ->where('skills.0.slug', $special->slug)
        );

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['collection' => 'core']))
        ->assertInertia(fn ($page) => $page->has('skills', 2));
});

it('searches by name and description', function () {
    Skill::factory()->create(['name' => 'Form 990 preparation', 'description' => 'Annual return.']);
    Skill::factory()->create(['name' => 'Donor pipeline', 'description' => 'Moves management.']);

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['search' => 'Form 990']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('skills', 1)
            ->where('skills.0.name', 'Form 990 preparation')
        );

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team, ['search' => 'moves management']))
        ->assertInertia(fn ($page) => $page->has('skills', 1));
});

it('shows a skill with its gate, rendered instructions and related tasks', function () {
    $related = Skill::factory()->create(['name' => 'Grant writing']);
    $skill = Skill::factory()->expertRequired()->create([
        'slug' => 'nonprofit-form-990',
        'supervision_note' => 'Filed with the IRS.',
        'body' => "## When to Use This Skill\n\nUse it for the annual return.",
    ]);
    $skill->relatedSkills()->attach($related);

    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team).'/nonprofit-form-990')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('skills/Show')
            ->where('skill.supervision', 'expert-required')
            ->where('skill.supervisionNote', 'Filed with the IRS.')
            ->where('skill.supervisionGate', fn (string $gate) => str_contains($gate, 'credentialed professional'))
            ->where('skill.body', fn (string $body) => str_contains($body, '<h2>'))
            ->has('skill.related', 1)
            ->where('skill.related.0.name', 'Grant writing')
        );
});

it('returns 404 for a skill that is not in the catalogue', function () {
    $this->actingAs($this->user)
        ->get(catalogueUrl($this->team).'/nonprofit-does-not-exist')
        ->assertNotFound();
});

it('does not let a user browse another team catalogue', function () {
    $otherTeam = Team::factory()->create();

    $this->actingAs($this->user)
        ->get(catalogueUrl($otherTeam))
        ->assertForbidden();
});
