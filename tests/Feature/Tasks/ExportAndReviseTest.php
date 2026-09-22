<?php

use App\Actions\RecordTaskDecision;
use App\Actions\SyncTeamCatalogue;
use App\Enums\ApprovalDecision;
use App\Enums\TaskRunStatus;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->base = '/'.$this->team->slug;

    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult('# The proposal\n\nBody text.', 'claude-opus-5', []);
        }
    });
});

describe('taking the work away', function () {
    it('will not hand over work that has not cleared its gate', function () {
        // The whole point of the gate is that unreleased work is not used.
        // A download button before sign-off would be the bypass.
        $run = TaskRun::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id.'/download')
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id)
            ->assertInertia(fn ($page) => $page
                ->where('run.isExportable', false)
                ->where('run.rawOutput', null)
            );
    });

    it('hands over released work as a file', function () {
        $run = TaskRun::factory()->create([
            'team_id' => $this->team->id,
            'output' => '# The proposal',
        ]);
        app(RecordTaskDecision::class)->handle($run, ApprovalDecision::Approved, user: $this->user);

        $response = $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id.'/download');

        $response->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8');

        expect($response->streamedContent())->toBe('# The proposal');
    });

    it('offers the draft for copying once it is released', function () {
        $run = TaskRun::factory()->create([
            'team_id' => $this->team->id,
            'output' => '# The proposal',
        ]);
        app(RecordTaskDecision::class)->handle($run, ApprovalDecision::Approved, user: $this->user);

        $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id)
            ->assertInertia(fn ($page) => $page
                ->where('run.isExportable', true)
                ->where('run.rawOutput', '# The proposal')
            );
    });

    it('will not hand over work released without an expert any differently', function () {
        // An override still releases it, so it is still exportable — the flag
        // is the control here, not a second lock.
        $run = TaskRun::factory()->awaitingExpert()->create([
            'team_id' => $this->team->id,
            'output' => '# The 990 worksheet',
        ]);
        app(RecordTaskDecision::class)->handle(
            $run,
            ApprovalDecision::ReleasedWithoutExpert,
            user: $this->user,
            justification: 'No CPA before the deadline.',
        );

        $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id.'/download')
            ->assertOk();
    });

    it('will not hand over another organization work', function () {
        $other = User::factory()->create();
        $run = TaskRun::factory()->released()->create([
            'team_id' => $other->personalTeam()->id,
            'output' => 'Theirs.',
        ]);

        $this->actingAs($this->user)
            ->get($this->base.'/tasks/'.$run->id.'/download')
            ->assertNotFound();
    });
});

describe('sending work back', function () {
    it('records why it was rejected', function () {
        $run = TaskRun::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$run->id.'/decision', [
                'decision' => 'rejected',
                'justification' => 'The ask amount is wrong.',
            ])
            ->assertRedirect();

        expect($run->refresh()->status)->toBe(TaskRunStatus::Rejected)
            ->and($run->approvals()->sole()->justification)->toBe('The ask amount is wrong.');
    });

    it('starts a second attempt that points back at the first', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        $rejected = TaskRun::factory()->create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
        ]);
        app(RecordTaskDecision::class)->handle($rejected, ApprovalDecision::Rejected, user: $this->user);

        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$rejected->id.'/revise', [
                'notes' => 'Ask for $25,000 instead.',
            ])
            ->assertRedirect();

        $revision = TaskRun::query()->latest('id')->first();

        expect($revision->id)->not->toBe($rejected->id)
            ->and($revision->revised_from_id)->toBe($rejected->id)
            ->and($revision->inputs['notes'])->toBe('Ask for $25,000 instead.')
            ->and($revision->skill_id)->toBe($skill->id);

        // The rejected run is never edited — it stays as it was decided.
        expect($rejected->refresh()->status)->toBe(TaskRunStatus::Rejected);
    });

    it('only offers a second attempt on work that was rejected', function () {
        $released = TaskRun::factory()->released()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$released->id.'/revise', ['notes' => 'Again.'])
            ->assertStatus(422);

        expect(TaskRun::count())->toBe(1);
    });

    it('does not let one organization revise another organization work', function () {
        $other = User::factory()->create();
        $run = TaskRun::factory()->create([
            'team_id' => $other->personalTeam()->id,
            'status' => TaskRunStatus::Rejected->value,
        ]);

        $this->actingAs($this->user)
            ->post($this->base.'/tasks/'.$run->id.'/revise', ['notes' => 'Mine now.'])
            ->assertNotFound();
    });
});
