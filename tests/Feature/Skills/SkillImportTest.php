<?php

use App\Enums\SupervisionLevel;
use App\Models\Skill;
use App\Models\SupervisionChange;
use App\Services\SkillLibraryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Build a throwaway library on disk so the importer is exercised against real
 * files rather than a mocked filesystem.
 */
function writeSkill(string $root, string $category, string $slug, array $overrides = []): string
{
    $meta = array_merge([
        'supervision' => 'review',
        'supervision_note' => 'A knowledgeable staff member should read this first.',
        'date_added' => '2026-09-07',
    ], $overrides['metadata'] ?? []);

    $frontmatter = [
        'name: '.$slug,
        'description: '.json_encode($overrides['description'] ?? 'Does a thing for nonprofits.'),
        'license: MIT',
        'metadata:',
    ];

    foreach ($meta as $key => $value) {
        $frontmatter[] = "  {$key}: ".json_encode($value);
    }

    $body = $overrides['body'] ?? "# {$slug}\n\n## When to Use This Skill\n\nUse it when the work calls for it.";

    $dir = "{$root}/skills/{$category}/{$slug}";
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/SKILL.md", "---\n".implode("\n", $frontmatter)."\n---\n".$body);

    return $dir;
}

beforeEach(function () {
    $this->libraryPath = storage_path('framework/testing/skill-library-'.uniqid());
    File::ensureDirectoryExists($this->libraryPath);
});

afterEach(function () {
    File::deleteDirectory($this->libraryPath);
});

it('imports skills from a library directory', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting');
    writeSkill($this->libraryPath, 'governance-compliance', 'nonprofit-form-990', [
        'metadata' => ['supervision' => 'expert-required', 'supervision_note' => 'Filed with the IRS.'],
    ]);

    $result = app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect($result['imported'])->toBe(2)
        ->and($result['created'])->toBe(2)
        ->and(Skill::count())->toBe(2);

    $form990 = Skill::query()->where('slug', 'nonprofit-form-990')->sole();

    expect($form990->supervision)->toBe(SupervisionLevel::ExpertRequired)
        ->and($form990->supervision_note)->toBe('Filed with the IRS.')
        ->and($form990->category)->toBe('governance-compliance')
        ->and($form990->license)->toBe('MIT')
        ->and($form990->date_added->toDateString())->toBe('2026-09-07');
});

it('is idempotent across repeated imports', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting');

    app(SkillLibraryImporter::class)->import($this->libraryPath);
    $second = app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect(Skill::count())->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['unchanged'])->toBe(1)
        ->and(SupervisionChange::count())->toBe(0);
});

it('records a supervision change when a level moves upstream', function () {
    writeSkill($this->libraryPath, 'community-development-finance', 'nonprofit-nmtc-deals');
    app(SkillLibraryImporter::class)->import($this->libraryPath);

    // The real library moved two community-finance skills up a tier in Sep 2026.
    writeSkill($this->libraryPath, 'community-development-finance', 'nonprofit-nmtc-deals', [
        'metadata' => ['supervision' => 'expert-required', 'supervision_note' => 'Counsel and CPA must review before filing.'],
    ]);
    $result = app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect($result['changes'])->toHaveCount(1);

    $change = SupervisionChange::query()->sole();

    expect($change->from_level)->toBe(SupervisionLevel::Review)
        ->and($change->to_level)->toBe(SupervisionLevel::ExpertRequired)
        ->and($change->isEscalation())->toBeTrue()
        ->and($change->acknowledgements()->count())->toBe(0);
});

it('records a loosened level as a non-escalation', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting', [
        'metadata' => ['supervision' => 'expert-required'],
    ]);
    app(SkillLibraryImporter::class)->import($this->libraryPath);

    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting', [
        'metadata' => ['supervision' => 'review'],
    ]);
    app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect(SupervisionChange::query()->sole()->isEscalation())->toBeFalse();
});

it('parses cross references between skills', function () {
    writeSkill($this->libraryPath, 'fundraising-development', 'nonprofit-grant-research', [
        'description' => 'Finds funders. Does not cover writing the proposal itself (use nonprofit-grant-writing).',
    ]);
    writeSkill($this->libraryPath, 'fundraising-development', 'nonprofit-grant-writing');

    $result = app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect($result['links'])->toBe(1);

    $research = Skill::query()->where('slug', 'nonprofit-grant-research')->sole();

    expect($research->relatedSkills->pluck('slug')->all())->toBe(['nonprofit-grant-writing']);
});

it('ignores cross references to skills that are not in the library', function () {
    writeSkill($this->libraryPath, 'fundraising-development', 'nonprofit-grant-research', [
        'description' => 'Does not cover the proposal (use nonprofit-does-not-exist).',
    ]);

    $result = app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect($result['links'])->toBe(0);
});

it('classifies special collections by category', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting');
    writeSkill($this->libraryPath, 'faith-based', 'nonprofit-faith-church-governance');

    app(SkillLibraryImporter::class)->import($this->libraryPath);

    expect(Skill::core()->count())->toBe(1)
        ->and(Skill::query()->where('is_core', false)->sole()->category)->toBe('faith-based');
});

it('computes a body hash and token estimate', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting', [
        'body' => str_repeat('a', 350),
    ]);

    app(SkillLibraryImporter::class)->import($this->libraryPath);

    $skill = Skill::query()->sole();

    expect($skill->body)->toBe(str_repeat('a', 350))
        ->and($skill->body_hash)->toBe(hash('sha256', str_repeat('a', 350)))
        ->and($skill->token_estimate)->toBe(100);
});

it('rejects an unknown supervision level rather than importing it', function () {
    writeSkill($this->libraryPath, 'finance-operations', 'nonprofit-budgeting', [
        'metadata' => ['supervision' => 'autonomous'],
    ]);

    // The library itself shipped an invalid "autonomous" label once; a silent
    // coercion would put work in the wrong gate, so this must fail loudly.
    expect(fn () => app(SkillLibraryImporter::class)->import($this->libraryPath))
        ->toThrow(RuntimeException::class, 'Unknown supervision level');

    expect(Skill::count())->toBe(0);
});

it('fails when the library directory is missing', function () {
    expect(fn () => app(SkillLibraryImporter::class)->import($this->libraryPath.'/nope'))
        ->toThrow(RuntimeException::class, 'Skill library not found');
});

it('fails when the directory contains no skills', function () {
    expect(fn () => app(SkillLibraryImporter::class)->import($this->libraryPath))
        ->toThrow(RuntimeException::class, 'No SKILL.md files found');
});

it('reports supervision changes through the console command', function () {
    writeSkill($this->libraryPath, 'governance-compliance', 'nonprofit-form-990');

    $this->artisan('skills:import', ['--path' => $this->libraryPath])
        ->assertSuccessful();

    writeSkill($this->libraryPath, 'governance-compliance', 'nonprofit-form-990', [
        'metadata' => ['supervision' => 'expert-required'],
    ]);

    $this->artisan('skills:import', ['--path' => $this->libraryPath])
        ->expectsOutputToContain('nonprofit-form-990')
        ->assertSuccessful();
});

it('fails the command when the library is missing', function () {
    $this->artisan('skills:import', ['--path' => $this->libraryPath.'/nope'])
        ->assertFailed();
});
