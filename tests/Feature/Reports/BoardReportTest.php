<?php

use App\Actions\RecordTaskDecision;
use App\Actions\SyncTeamCatalogue;
use App\Enums\ApprovalDecision;
use App\Enums\CredentialType;
use App\Enums\SupervisionLevel;
use App\Models\Skill;
use App\Models\SupervisionChange;
use App\Models\SupervisionChangeAcknowledgement;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->base = '/'.$this->team->slug.'/report';
    $this->gate = app(RecordTaskDecision::class);
});

it('requires authentication', function () {
    $this->get($this->base)->assertRedirect('/login');
});

it('counts what was run, released and let through', function () {
    TaskRun::factory()->count(2)->create(['team_id' => $this->team->id]);

    $approved = TaskRun::factory()->create(['team_id' => $this->team->id]);
    $this->gate->handle($approved, ApprovalDecision::Approved, user: $this->user);

    $override = TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);
    $this->gate->handle(
        $override,
        ApprovalDecision::ReleasedWithoutExpert,
        user: $this->user,
        justification: 'No CPA available before the deadline.',
    );

    $this->actingAs($this->user)
        ->get($this->base)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Board')
            ->where('totals.runs', 4)
            ->where('totals.released', 2)
            ->where('totals.awaiting', 2)
            ->where('totals.releasedWithoutExpert', 1)
            ->has('overrides', 1)
            ->where('overrides.0.justification', 'No CPA available before the deadline.')
            ->where('overrides.0.by', $this->user->name)
        );
});

it('lists professional sign-offs separately from overrides', function () {
    $run = TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);

    $this->gate->handle(
        $run,
        ApprovalDecision::Approved,
        user: $this->user,
        credentialType: CredentialType::Cpa,
        credentialReference: 'OH-12345',
    );

    $this->actingAs($this->user)
        ->get($this->base)
        ->assertInertia(fn ($page) => $page
            ->has('expertSignOffs', 1)
            ->where('expertSignOffs.0.credential', 'CPA')
            ->where('expertSignOffs.0.licence', 'OH-12345')
            ->has('overrides', 0)
            ->where('totals.releasedWithoutExpert', 0)
        );
});

it('only counts work inside the chosen period', function () {
    TaskRun::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => now()->subDays(200),
    ]);
    TaskRun::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user)
        ->get($this->base.'?days=30')
        ->assertInertia(fn ($page) => $page->where('totals.runs', 1));

    $this->actingAs($this->user)
        ->get($this->base.'?days=365')
        ->assertInertia(fn ($page) => $page->where('totals.runs', 2));
});

it('falls back to 90 days for an unsupported period', function () {
    $this->actingAs($this->user)
        ->get($this->base.'?days=9999')
        ->assertInertia(fn ($page) => $page->where('period.days', 90));
});

it('never counts another organization work', function () {
    $other = User::factory()->create();
    TaskRun::factory()->count(3)->create(['team_id' => $other->personalTeam()->id]);

    $this->actingAs($this->user)
        ->get($this->base)
        ->assertInertia(fn ($page) => $page->where('totals.runs', 0));
});

describe('supervision change alerts', function () {
    beforeEach(function () {
        $this->skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        $this->change = SupervisionChange::create([
            'skill_id' => $this->skill->id,
            'from_level' => SupervisionLevel::Review->value,
            'to_level' => SupervisionLevel::ExpertRequired->value,
        ]);
    });

    it('surfaces a level that moved on enabled work', function () {
        $this->actingAs($this->user)
            ->get($this->base)
            ->assertInertia(fn ($page) => $page
                ->has('supervisionChanges', 1)
                ->where('supervisionChanges.0.isEscalation', true)
                ->where('supervisionChanges.0.to', 'Expert required')
            );
    });

    it('ignores a change to work the organization does not run', function () {
        $unused = Skill::factory()->specialCollection('arts-culture')->create();
        SupervisionChange::create([
            'skill_id' => $unused->id,
            'from_level' => SupervisionLevel::Review->value,
            'to_level' => SupervisionLevel::ExpertRequired->value,
        ]);

        $this->actingAs($this->user)
            ->get($this->base)
            ->assertInertia(fn ($page) => $page->has('supervisionChanges', 1));
    });

    it('clears once acknowledged', function () {
        $this->actingAs($this->user)
            ->post($this->base.'/changes/'.$this->change->id)
            ->assertRedirect();

        expect(SupervisionChangeAcknowledgement::query()->sole()->team_id)
            ->toBe($this->team->id);

        $this->actingAs($this->user)
            ->get($this->base)
            ->assertInertia(fn ($page) => $page->has('supervisionChanges', 0));
    });

    it('stays unacknowledged for a different organization', function () {
        // Acknowledgement is per-tenant: one organization clearing an alert
        // must not silently clear it for everybody else.
        $otherUser = User::factory()->create();
        $otherTeam = $otherUser->personalTeam();
        app(SyncTeamCatalogue::class)->handle($otherTeam);

        $this->actingAs($this->user)->post($this->base.'/changes/'.$this->change->id);

        $this->actingAs($otherUser)
            ->get('/'.$otherTeam->slug.'/report')
            ->assertInertia(fn ($page) => $page->has('supervisionChanges', 1));
    });
});

describe('the export', function () {
    it('streams the audit record as a csv', function () {
        $run = TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);
        $this->gate->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'Deadline could not move.',
        );

        $response = $this->actingAs($this->user)->get($this->base.'/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        expect($csv)->toContain('Released without expert')
            ->and($csv)->toContain('Deadline could not move.')
            ->and($csv)->toContain('YES')
            ->and($csv)->toContain($run->skill->name);
    });

    it('does not export another organization work', function () {
        $other = User::factory()->create();
        $theirs = TaskRun::factory()->create(['team_id' => $other->personalTeam()->id]);

        $csv = $this->actingAs($this->user)
            ->get($this->base.'/export')
            ->streamedContent();

        expect($csv)->not->toContain($theirs->skill->name);
    });
});
