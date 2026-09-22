<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\SkillLibraryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * The failures these cover are all silent ones: work that comes back generic,
 * or fake, or a catalogue that offers what it cannot run. None of them throws,
 * and every one of them teaches a first-time user the wrong lesson about the
 * product.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->skill = Skill::factory()->create(['slug' => 'nonprofit-appeals']);
    app(SyncTeamCatalogue::class)->handle($this->team);
});

it('gives a task the name a person would read, not the library slug', function () {
    $file = sys_get_temp_dir().'/names-'.uniqid().'/skills/nonprofit-core/nonprofit-mergers-fiscal-sponsorship';
    mkdir($file, 0777, true);
    file_put_contents($file.'/SKILL.md', <<<'MD'
    ---
    name: nonprofit-mergers-fiscal-sponsorship
    description: A task.
    metadata:
      supervision: review
      supervision_note: Someone reads it.
    ---

    # Nonprofit Mergers & Fiscal Sponsorship

    ## When to Use This Skill
    Whenever.
    MD);

    $parsed = app(SkillLibraryImporter::class)->parse($file.'/SKILL.md');

    // The ampersand is the point: it exists in the heading and never in the
    // slug, so headlining the slug could not have recovered it.
    expect($parsed['name'])->toBe('Nonprofit Mergers & Fiscal Sponsorship')
        ->and($parsed['slug'])->toBe('nonprofit-mergers-fiscal-sponsorship');
});

it('falls back to the slug when a body has no heading', function () {
    $file = sys_get_temp_dir().'/names-'.uniqid().'/skills/nonprofit-core/nonprofit-annual-appeals';
    mkdir($file, 0777, true);
    file_put_contents($file.'/SKILL.md', <<<'MD'
    ---
    name: nonprofit-annual-appeals
    description: A task.
    metadata:
      supervision: review
      supervision_note: Someone reads it.
    ---

    ## When to Use This Skill
    Whenever.
    MD);

    expect(app(SkillLibraryImporter::class)->parse($file.'/SKILL.md')['name'])
        ->toBe('Nonprofit Annual Appeals');
});

it('warns that a thin profile produces generic work, and still lets it run', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-test',
    ]);

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/skills/'.$this->skill->slug)
        ->assertInertia(fn ($page) => $page
            ->where('hasAiProvider', true)
            ->where('missingContext.0', 'what the organization does')
        );

    // The warning is a warning. A wall here is what sends someone back to
    // pasting prompts into ChatGPT.
    $this->actingAs($this->user)
        ->post('/'.$this->team->slug.'/tasks', ['skill' => $this->skill->slug])
        ->assertRedirect();

    expect(TaskRun::count())->toBe(1);
});

it('stops warning once the profile says something', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'ai_provider' => AiProvider::OpenAi,
        'ai_api_key' => 'sk-test',
        'mission' => 'Transitional housing for families in Akron.',
        'entity_type' => '501(c)(3) public charity',
        'budget_band' => '1m_to_5m',
        'state_of_incorporation' => 'OH',
    ]);

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/skills/'.$this->skill->slug)
        ->assertInertia(fn ($page) => $page->where('missingContext', []));
});

it('says a draft was produced with little to go on', function () {
    $run = TaskRun::factory()->for($this->team)->for($this->skill)->create();

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/tasks/'.$run->id)
        ->assertInertia(fn ($page) => $page->where('run.thinContext', true));
});

it('marks placeholder output as not a draft', function () {
    $run = TaskRun::factory()->for($this->team)->for($this->skill)
        ->create(['model' => 'placeholder']);

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/tasks/'.$run->id)
        ->assertInertia(fn ($page) => $page->where('run.isPlaceholder', true));
});

it('says which catalogue entries this organization can actually run', function () {
    // A special collection nobody opted into.
    $unavailable = Skill::factory()->specialCollection()->create();

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/skills')
        ->assertInertia(function ($page) use ($unavailable) {
            $skills = collect($page->toArray()['props']['skills']);

            expect($skills->firstWhere('slug', test()->skill->slug)['enabled'])->toBeTrue()
                ->and($skills->firstWhere('slug', $unavailable->slug)['enabled'])->toBeFalse();

            return $page;
        });
});

it('tells an empty catalogue apart from an over-narrow filter', function () {
    Skill::query()->delete();

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/skills')
        ->assertInertia(fn ($page) => $page->where('libraryIsEmpty', true));

    Skill::factory()->create();

    $this->actingAs($this->user)
        ->get('/'.$this->team->slug.'/skills?search=nothingmatchesthis')
        ->assertInertia(fn ($page) => $page->where('libraryIsEmpty', false)->where('skills', []));
});
