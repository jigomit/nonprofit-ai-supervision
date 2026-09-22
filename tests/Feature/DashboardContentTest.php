<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\SupervisionLevel;
use App\Enums\TeamRole;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\SupervisionChange;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->url = '/'.$this->team->slug.'/dashboard';
});

it('leads a brand new organization to set itself up', function () {
    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('setup.isOnboarded', false)
            ->where('setup.hasRun', false)
            ->has('awaitingDecision', 0)
        );
});

it('knows when the organization has been set up', function () {
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'onboarded_at' => now(),
    ]);
    Skill::factory()->count(3)->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->where('setup.isOnboarded', true)
            ->where('setup.catalogueSize', 3)
            ->where('setup.hasSchedules', false)
        );
});

it('lists work waiting at a gate, oldest first', function () {
    $older = TaskRun::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => now()->subDays(2),
    ]);
    TaskRun::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->has('awaitingDecision', 2)
            ->where('awaitingDecision.0.id', $older->id)
        );
});

it('does not ask a member to clear an expert gate they cannot clear', function () {
    // Listing it would be a to-do they have no way to do.
    TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);
    TaskRun::factory()->create(['team_id' => $this->team->id]);

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->has('awaitingDecision', 1)
            ->where('awaitingDecision.0.supervision', SupervisionLevel::Review->value)
        );

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page->has('awaitingDecision', 2));
});

it('surfaces work the calendar says is due', function () {
    TaskSchedule::factory()->overdue()->create(['team_id' => $this->team->id]);
    TaskSchedule::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->has('dueWork', 1)
            ->where('dueWork.0.isOverdue', true)
        );
});

it('counts the last thirty days and flags what went out unsupervised', function () {
    TaskRun::factory()->released()->create(['team_id' => $this->team->id]);
    TaskRun::factory()->released()->create([
        'team_id' => $this->team->id,
        'released_without_expert' => true,
    ]);
    TaskRun::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => now()->subDays(60),
    ]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->where('summary.runs', 2)
            ->where('summary.released', 2)
            ->where('summary.releasedWithoutExpert', 1)
        );
});

it('raises a supervision change on work the organization runs', function () {
    $skill = Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);

    SupervisionChange::create([
        'skill_id' => $skill->id,
        'from_level' => SupervisionLevel::Review->value,
        'to_level' => SupervisionLevel::ExpertRequired->value,
    ]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page->where('summary.supervisionChanges', 1));
});

it('shows nothing belonging to another organization', function () {
    $other = User::factory()->create();
    TaskRun::factory()->count(3)->create(['team_id' => $other->personalTeam()->id]);
    TaskSchedule::factory()->overdue()->create(['team_id' => $other->personalTeam()->id]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn ($page) => $page
            ->has('awaitingDecision', 0)
            ->has('dueWork', 0)
            ->has('recentRuns', 0)
            ->where('summary.runs', 0)
        );
});
