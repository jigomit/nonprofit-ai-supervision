<?php

use App\Models\Skill;
use App\Models\User;
use App\Services\SkillLibraryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 95 of the 102 skills say in writing where the work usually goes wrong, and
 * until now only the model ever read it. These assert it is lifted out of the
 * body and put in front of the person before they start.
 */
uses(RefreshDatabase::class);

function skillFile(string $body): string
{
    $dir = sys_get_temp_dir().'/preflight-'.uniqid().'/skills/nonprofit-core/nonprofit-test';
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/SKILL.md', <<<MD
    ---
    name: nonprofit-test
    description: A task for the test suite.
    metadata:
      supervision: review
      supervision_note: Someone has to read it.
    ---

    {$body}
    MD);

    return $dir.'/SKILL.md';
}

it('lifts dashed failure modes out of the body', function () {
    $parsed = app(SkillLibraryImporter::class)->parse(skillFile(<<<'MD'
    ## When to Use This Skill
    Whenever.

    ## Common Failure Modes
    - **One ask for everyone**: ignoring giving history produces both under-asks
      and over-asks.
    - **Weak P.S.**: skipping the highest-read line of the letter.

    ## Standard Deliverables
    - An appeal letter.
    MD));

    expect($parsed['failure_modes'])->toHaveCount(2)
        // A wrapped line belongs to the bullet above it, not to a new one.
        ->and($parsed['failure_modes'][0])->toBe(
            '**One ask for everyone**: ignoring giving history produces both under-asks and over-asks.'
        )
        ->and($parsed['failure_modes'][1])->toStartWith('**Weak P.S.**')
        ->and($parsed['deliverables'])->toBe(['An appeal letter.']);
});

it('reads numbered deliverables too', function () {
    // 13 of the 64 skills that list deliverables number them instead.
    $parsed = app(SkillLibraryImporter::class)->parse(skillFile(<<<'MD'
    ## Standard Deliverables
    1. **Partnership charter** — the decision-rights matrix.
    2. **Diagnostic memo** — which model the organization actually runs.
    MD));

    expect($parsed['deliverables'])->toHaveCount(2)
        ->and($parsed['deliverables'][1])->toContain('Diagnostic memo');
});

it('stops at the next heading', function () {
    $parsed = app(SkillLibraryImporter::class)->parse(skillFile(<<<'MD'
    ## Common Failure Modes
    - The only failure mode.

    ## Something Else
    - Not a failure mode.
    MD));

    expect($parsed['failure_modes'])->toBe(['The only failure mode.']);
});

it('is content with a skill that names neither', function () {
    $parsed = app(SkillLibraryImporter::class)->parse(skillFile("## When to Use This Skill\nWhenever."));

    expect($parsed['failure_modes'])->toBe([])
        ->and($parsed['deliverables'])->toBe([]);
});

it('puts the warnings in front of the person, not only the model', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();

    $skill = Skill::factory()->create([
        'failure_modes' => ['**Weak P.S.**: skipping the highest-read line.'],
        'deliverables' => ['An appeal letter.'],
    ]);

    $this->actingAs($user)
        ->get('/'.$team->slug.'/skills/'.$skill->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('skills/Show')
            ->where('skill.failureModes.0', "<p><strong>Weak P.S.</strong>: skipping the highest-read line.</p>\n")
            ->where('skill.deliverables.0', "<p>An appeal letter.</p>\n")
        );
});
