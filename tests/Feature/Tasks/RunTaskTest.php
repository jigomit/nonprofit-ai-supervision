<?php

use App\Actions\StartTaskRun;
use App\Actions\SyncTeamCatalogue;
use App\Enums\ExpertGatePolicy;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Exceptions\GateViolation;
use App\Jobs\RunTaskJob;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;
use App\Services\TaskPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
});

/** Swap in an executor that returns a known answer without calling anything. */
function fakeExecutor(string $output = 'A draft.', array $usage = []): void
{
    app()->bind(TaskExecutor::class, fn () => new class($output, $usage) implements TaskExecutor
    {
        public function __construct(private string $output, private array $usage) {}

        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult($this->output, 'claude-opus-5', $this->usage);
        }
    });
}

function enabledSkill(mixed $team, array $attributes = []): Skill
{
    $skill = Skill::factory()->create($attributes);
    app(SyncTeamCatalogue::class)->handle($team);

    return $skill;
}

it('queues a run and snapshots the rules in force', function () {
    Queue::fake();

    $skill = enabledSkill($this->team);
    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'expert_gate_policy' => ExpertGatePolicy::Block->value,
    ]);

    $run = app(StartTaskRun::class)->handle(
        $this->team->refresh(),
        $skill,
        $this->user,
        ['notes' => 'For the spring appeal.'],
    );

    expect($run->status)->toBe(TaskRunStatus::Queued)
        ->and($run->supervision_at_run)->toBe(SupervisionLevel::Review)
        ->and($run->expert_gate_policy_at_run)->toBe(ExpertGatePolicy::Block)
        ->and($run->skill_body_hash)->toBe($skill->body_hash)
        ->and($run->inputs)->toBe(['notes' => 'For the spring appeal.']);

    Queue::assertPushed(RunTaskJob::class);
});

it('refuses to run a task that is not in the catalogue', function () {
    $skill = Skill::factory()->create();

    expect(fn () => app(StartTaskRun::class)->handle($this->team, $skill, $this->user))
        ->toThrow(GateViolation::class, 'not enabled in this organization');

    expect(TaskRun::count())->toBe(0);
});

it("honours the organization's stricter override when snapshotting", function () {
    Queue::fake();
    $skill = enabledSkill($this->team);

    $this->team->skills()->updateExistingPivot($skill->id, [
        'supervision_override' => SupervisionLevel::ExpertRequired->value,
    ]);

    $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

    expect($run->supervision_at_run)->toBe(SupervisionLevel::ExpertRequired);
});

it('sends unsupervised work straight out', function () {
    fakeExecutor('The newsletter draft.');
    $skill = enabledSkill($this->team, ['supervision' => SupervisionLevel::Unsupervised->value]);

    $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

    expect($run->refresh()->status)->toBe(TaskRunStatus::Released)
        ->and($run->output)->toBe('The newsletter draft.')
        ->and($run->released_at)->not->toBeNull()
        ->and($run->released_without_expert)->toBeFalse();
});

it('holds review work at the review gate', function () {
    fakeExecutor();
    $skill = enabledSkill($this->team, ['supervision' => SupervisionLevel::Review->value]);

    $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

    expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingReview)
        ->and($run->released_at)->toBeNull()
        ->and($run->isReleased())->toBeFalse();
});

it('holds expert work at the expert gate', function () {
    fakeExecutor();
    $skill = enabledSkill($this->team, ['supervision' => SupervisionLevel::ExpertRequired->value]);

    $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

    expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingExpert)
        ->and($run->isReleased())->toBeFalse();
});

it('records a failure instead of releasing anything', function () {
    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            throw new RuntimeException('The model declined to produce this output.');
        }
    });

    $skill = enabledSkill($this->team, ['supervision' => SupervisionLevel::Unsupervised->value]);

    app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);
    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Failed)
        ->and($run->failure_reason)->toContain('declined')
        ->and($run->output)->toBeNull()
        ->and($run->released_at)->toBeNull();
});

it('does not re-run work that already reached a gate', function () {
    fakeExecutor('First draft.');
    $skill = enabledSkill($this->team);
    $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

    expect($run->refresh()->status)->toBe(TaskRunStatus::AwaitingReview);

    // A retry must not overwrite output a reviewer may already be reading.
    fakeExecutor('Second draft.');
    (new RunTaskJob($run))->handle(app(TaskExecutor::class));

    expect($run->refresh()->output)->toBe('First draft.');
});

describe('prompt assembly', function () {
    beforeEach(function () {
        $this->skill = Skill::factory()->create(['body' => "## When to Use This Skill\n\nDraft the appeal."]);
        OrganizationProfile::create([
            'team_id' => $this->team->id,
            'entity_type' => '501(c)(3) public charity',
            'state_of_incorporation' => 'OH',
            'mission' => 'Housing support in Akron.',
        ]);
        $this->run = TaskRun::factory()->create([
            'team_id' => $this->team->id,
            'skill_id' => $this->skill->id,
            'requested_by' => $this->user->id,
            'inputs' => ['notes' => 'Target lapsed donors.'],
        ]);
        $this->builder = app(TaskPromptBuilder::class);
    });

    it('sends the skill body verbatim as a cached system block', function () {
        $system = $this->builder->system($this->run);

        expect($system)->toHaveCount(1)
            ->and($system[0]['text'])->toBe($this->skill->body)
            ->and($system[0]['cacheControl']['type'])->toBe('ephemeral');
    });

    it('keeps everything organization-specific out of the cached block', function () {
        // The invariant that protects the bill: one interpolated value here
        // makes every request a cache miss, silently, at roughly ten times
        // the cost.
        $cached = $this->builder->system($this->run)[0]['text'];

        expect($cached)->not->toContain('Akron')
            ->and($cached)->not->toContain('OH')
            ->and($cached)->not->toContain($this->team->name)
            ->and($cached)->not->toContain('lapsed donors');
    });

    it('puts the organization context and inputs in the message', function () {
        $content = $this->builder->messages($this->run)[0]['content'];

        expect($content)->toContain('Housing support in Akron.')
            ->and($content)->toContain('501(c)(3) public charity')
            ->and($content)->toContain('Target lapsed donors')
            ->and($content)->toContain($this->team->name);
    });

    it('tells the model the output faces a human gate', function () {
        $content = $this->builder->messages($this->run)[0]['content'];

        expect($content)->toContain('goes to a human gate')
            ->and($content)->toContain('knowledgeable staff member');
    });

    it('says plainly when it has no organization details to work from', function () {
        $bare = TaskRun::factory()->create(['skill_id' => $this->skill->id]);

        expect($this->builder->messages($bare)[0]['content'])
            ->toContain('Ask for anything you need rather than assuming it');
    });
});

describe('execution results', function () {
    it('reports whether the cached instructions were reused', function () {
        expect((new ExecutionResult('x', 'claude-opus-5', ['cache_read_input_tokens' => 4800]))->servedFromCache())
            ->toBeTrue()
            ->and((new ExecutionResult('x', 'claude-opus-5', ['cache_read_input_tokens' => 0]))->servedFromCache())
            ->toBeFalse()
            ->and((new ExecutionResult('x', 'claude-opus-5'))->cacheReadTokens())->toBe(0);
    });

    it('stores usage on the run so a cache regression is visible', function () {
        fakeExecutor('Draft.', ['cache_read_input_tokens' => 4800, 'output_tokens' => 900]);
        $skill = enabledSkill($this->team, ['supervision' => SupervisionLevel::Unsupervised->value]);

        $run = app(StartTaskRun::class)->handle($this->team->refresh(), $skill, $this->user);

        expect($run->refresh()->usage['cache_read_input_tokens'])->toBe(4800)
            ->and($run->model)->toBe('claude-opus-5');
    });
});
