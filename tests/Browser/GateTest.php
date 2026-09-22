<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\SupervisionLevel;
use App\Enums\TaskRunStatus;
use App\Models\ExpertInvitation;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;

/**
 * The product's only real promise, exercised the way a person meets it.
 *
 * The feature tests prove the gate cannot be bypassed through the action. These
 * prove the interface does not offer a way around it either — that the override
 * is not sitting next to Approve, and that an expert who has never logged in
 * can still clear a gate.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);

    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult('The drafted document.', 'claude-opus-5', []);
        }
    });
});

function runnableSkill(mixed $team, SupervisionLevel $level, array $attributes = []): Skill
{
    $skill = Skill::factory()->create([...$attributes, 'supervision' => $level->value]);
    app(SyncTeamCatalogue::class)->handle($team);

    return $skill;
}

it('runs a task and holds it at the review gate', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::Review, [
        'name' => 'Annual appeal letter',
        'supervision_note' => 'A knowledgeable staff member should read it.',
    ]);

    visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->assertSee('Run this task')
        ->type('#notes', 'For the spring appeal.')
        ->click('Run task')
        ->assertSee('The drafted document.')
        ->assertSee('Waiting for review')
        ->assertSee('A knowledgeable staff member should read it.')
        ->assertNoJavascriptErrors();

    expect(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::AwaitingReview);
});

it('releases review work when someone approves it', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::Review);

    visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->click('Run task')
        ->click('Approve and release')
        ->assertSee('Released')
        ->assertNoJavascriptErrors();

    expect(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::Released);
});

it('does not offer a plain approval as a way past the expert gate', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::ExpertRequired, [
        'supervision_note' => 'Filed with the IRS.',
    ]);

    $page = visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->click('Run task')
        ->assertSee('Waiting for an expert')
        // The release is available, but only by claiming a credential or by
        // taking the override — which is behind its own disclosure.
        ->assertSee('Your professional standing')
        ->assertSee('No expert available?')
        ->assertDontSee('Release without expert review');

    $page->click('Approve and release')
        ->assertSee('needs a credentialed professional')
        ->assertNoJavascriptErrors();

    expect(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::AwaitingExpert);
});

it('keeps the override behind a disclosure and a written reason', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::ExpertRequired);

    $page = visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->click('Run task')
        ->click('No expert available?')
        ->assertSee('It stays flagged on this run')
        ->click('Release without expert review')
        // Refused: no reason given.
        ->assertSee('who decided and why');

    expect(TaskRun::query()->sole()->released_without_expert)->toBeFalse();

    $page->type('#justification', 'Board chair is a retired CPA and reviewed it.')
        ->click('Release without expert review')
        ->assertSee('Released without expert')
        ->assertNoJavascriptErrors();

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Released)
        ->and($run->released_without_expert)->toBeTrue();
});

it('lets an invited expert clear a gate without ever logging in', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::ExpertRequired, [
        'supervision_note' => 'Filed with the IRS.',
    ]);

    visit('/'.$this->team->slug.'/skills/'.$skill->slug)->click('Run task');

    $run = TaskRun::query()->sole();
    $invitation = ExpertInvitation::create([
        'token' => ExpertInvitation::generateToken(),
        'task_run_id' => $run->id,
        'invited_by' => $this->owner->id,
        'email' => 'cpa@example.com',
        'expires_at' => now()->addDays(14),
    ]);

    // A fresh visitor with no session at all.
    auth()->logout();

    visit('/expert-review/'.$invitation->token)
        ->assertSee('Filed with the IRS.')
        ->assertSee('The draft, as produced')
        ->type('#approver_name', 'Dana Reyes')
        ->select('#credential_type', 'cpa')
        ->type('#credential_reference', 'OH-12345')
        ->click('Approve and release')
        ->assertSee('your decision has been recorded')
        ->assertNoJavascriptErrors();

    $run->refresh();

    expect($run->status)->toBe(TaskRunStatus::Released)
        ->and($run->released_without_expert)->toBeFalse()
        ->and($run->approvals()->sole()->credential_reference)->toBe('OH-12345');
});

it('tells a used expert link it is finished', function () {
    $run = TaskRun::factory()->awaitingExpert()->create(['team_id' => $this->team->id]);
    $invitation = ExpertInvitation::create([
        'token' => ExpertInvitation::generateToken(),
        'task_run_id' => $run->id,
        'invited_by' => $this->owner->id,
        'email' => 'cpa@example.com',
        'expires_at' => now()->addDays(14),
        'used_at' => now(),
    ]);

    auth()->logout();

    visit('/expert-review/'.$invitation->token)
        ->assertSee('no longer active')
        ->assertDontSee('Approve and release')
        ->assertNoJavascriptErrors();
});

it('only offers the document once it has been signed off', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::Review);

    $page = visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->click('Run task')
        ->assertSee('Available to copy once it has been signed off')
        ->assertDontSee('Download');

    $page->click('Approve and release')
        ->assertSee('The document')
        ->assertSee('Download')
        ->assertNoJavascriptErrors();
});

it('lets rejected work be sent round again', function () {
    $skill = runnableSkill($this->team, SupervisionLevel::Review);

    visit('/'.$this->team->slug.'/skills/'.$skill->slug)
        ->click('Run task')
        ->type('#decision_notes', 'The ask amount is wrong.')
        ->click('Reject')
        ->assertSee('Try again')
        ->type('#revision_notes', 'Ask for $25,000 instead.')
        ->click('Start a revised run')
        ->assertSee('This is a second attempt')
        ->assertNoJavascriptErrors();

    expect(TaskRun::query()->latest('id')->first()->revised_from_id)->not->toBeNull();
});
