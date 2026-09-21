<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\SupervisionLevel;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\Team;
use App\Models\TeamSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = User::factory()->create()->personalTeam();
});

it('gives every organization the core categories', function () {
    Skill::factory()->count(4)->create();
    Skill::factory()->specialCollection('faith-based')->count(3)->create();

    $result = app(SyncTeamCatalogue::class)->handle($this->team);

    expect($result['enabled'])->toBe(4)
        ->and($result['added'])->toBe(4)
        ->and($this->team->enabledSkills()->count())->toBe(4);
});

it('adds the special collections an organization opted into', function () {
    Skill::factory()->count(2)->create();
    Skill::factory()->specialCollection('faith-based')->count(3)->create();
    Skill::factory()->specialCollection('arts-culture')->count(5)->create();

    $profile = OrganizationProfile::create([
        'team_id' => $this->team->id,
        'collections' => ['faith-based'],
    ]);

    app(SyncTeamCatalogue::class)->handle($this->team, $profile);

    expect($this->team->enabledSkills()->count())->toBe(5)
        ->and($this->team->enabledSkills()->where('category', 'arts-culture')->count())->toBe(0);
});

it('disables rather than deletes a collection that is turned off', function () {
    Skill::factory()->create();
    $faith = Skill::factory()->specialCollection('faith-based')->create();

    $profile = OrganizationProfile::create([
        'team_id' => $this->team->id,
        'collections' => ['faith-based'],
    ]);
    app(SyncTeamCatalogue::class)->handle($this->team, $profile);

    // The organization tightened this task before turning the collection off.
    $this->team->skills()->updateExistingPivot($faith->id, [
        'supervision_override' => SupervisionLevel::ExpertRequired->value,
    ]);

    $profile->update(['collections' => []]);
    $result = app(SyncTeamCatalogue::class)->handle($this->team, $profile->refresh());

    expect($result['disabled'])->toBe(1)
        ->and($this->team->enabledSkills()->count())->toBe(1)
        ->and($this->team->skills()->count())->toBe(2);

    // Turning it back on must restore the override, not reset it.
    $profile->update(['collections' => ['faith-based']]);
    app(SyncTeamCatalogue::class)->handle($this->team, $profile->refresh());

    $pivot = $this->team->skills()->where('skills.id', $faith->id)->sole()->pivot;

    expect($pivot->enabled)->toBeTrue()
        ->and($pivot->supervision_override)->toBe(SupervisionLevel::ExpertRequired);
});

it('is idempotent and picks up newly imported skills', function () {
    Skill::factory()->count(3)->create();

    app(SyncTeamCatalogue::class)->handle($this->team);
    $second = app(SyncTeamCatalogue::class)->handle($this->team);

    expect($second['added'])->toBe(0)
        ->and($this->team->skills()->count())->toBe(3);

    // A later library import adds a task; the next sync should pick it up.
    Skill::factory()->create();
    $third = app(SyncTeamCatalogue::class)->handle($this->team);

    expect($third['added'])->toBe(1)
        ->and($this->team->enabledSkills()->count())->toBe(4);
});

it('keeps one catalogue separate from another', function () {
    Skill::factory()->count(2)->create();
    $otherTeam = Team::factory()->create();

    app(SyncTeamCatalogue::class)->handle($this->team);

    expect($this->team->skills()->count())->toBe(2)
        ->and($otherTeam->skills()->count())->toBe(0);
});

describe('supervision overrides', function () {
    it('uses the library level when no override is set', function () {
        $pivot = new TeamSkill;

        expect($pivot->effectiveSupervision(SupervisionLevel::Review))
            ->toBe(SupervisionLevel::Review);
    });

    it('lets an organization demand more supervision than the library', function () {
        $pivot = new TeamSkill;
        $pivot->overrideSupervision(SupervisionLevel::ExpertRequired, SupervisionLevel::Review);

        expect($pivot->effectiveSupervision(SupervisionLevel::Review))
            ->toBe(SupervisionLevel::ExpertRequired);
    });

    it('refuses an override that would loosen the gate', function () {
        $pivot = new TeamSkill;

        expect(fn () => $pivot->overrideSupervision(
            SupervisionLevel::Review,
            SupervisionLevel::ExpertRequired,
        ))->toThrow(InvalidArgumentException::class, 'Cannot lower supervision');

        expect($pivot->supervision_override)->toBeNull();
    });

    it('never applies a stored override that is looser than the library level', function () {
        // Defence in depth: if the library tightens a level after an override
        // was stored, the stricter library level has to win.
        $pivot = new TeamSkill;
        $pivot->overrideSupervision(SupervisionLevel::Review, SupervisionLevel::Unsupervised);

        expect($pivot->effectiveSupervision(SupervisionLevel::ExpertRequired))
            ->toBe(SupervisionLevel::ExpertRequired);
    });
});
