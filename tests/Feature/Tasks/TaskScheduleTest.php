<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\Cadence;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\User;
use App\Services\ExecutionResult;
use App\Services\TaskExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->base = '/'.$this->team->slug;

    app()->bind(TaskExecutor::class, fn () => new class implements TaskExecutor
    {
        public function execute(TaskRun $run): ExecutionResult
        {
            return new ExecutionResult('A draft.', 'claude-opus-5', []);
        }
    });
});

describe('when work first falls due', function () {
    it('uses the anchor month and day', function () {
        $due = TaskSchedule::firstDueDate(
            cadence: Cadence::Annually,
            from: Carbon::parse('2026-01-10'),
            anchorMonth: 6,
            anchorDay: 15,
        );

        expect($due->toDateString())->toBe('2026-06-15');
    });

    it('is never born overdue', function () {
        // An annual task whose anchor has already passed this year belongs to
        // next year, not to yesterday.
        $due = TaskSchedule::firstDueDate(
            cadence: Cadence::Annually,
            from: Carbon::parse('2026-09-22'),
            anchorMonth: 6,
            anchorDay: 15,
        );

        expect($due->toDateString())->toBe('2027-06-15');
    });

    it('rolls a monthly task to next month once this one has passed', function () {
        $due = TaskSchedule::firstDueDate(
            cadence: Cadence::Monthly,
            from: Carbon::parse('2026-09-22'),
            anchorDay: 5,
        );

        expect($due->toDateString())->toBe('2026-10-05');
    });

    it('counts from the fiscal year end when anchored to it', function () {
        // A 990 is due four and a half months after year end. With a June year
        // end that is mid-November, and the schedule should say so without
        // anyone doing the arithmetic.
        $due = TaskSchedule::firstDueDate(
            cadence: Cadence::Annually,
            from: Carbon::parse('2026-07-01'),
            anchoredToFiscalYearEnd: true,
            anchorDay: 15,
            monthsAfterAnchor: 4,
            fiscalYearEndMonth: 6,
        );

        expect($due->toDateString())->toBe('2026-10-15');
    });

    it('moves with the organization fiscal year', function () {
        $december = TaskSchedule::firstDueDate(
            cadence: Cadence::Annually,
            from: Carbon::parse('2026-01-01'),
            anchoredToFiscalYearEnd: true,
            anchorDay: 15,
            monthsAfterAnchor: 4,
            fiscalYearEndMonth: 12,
        );

        // A December year end pushes the same task into the following April.
        expect($december->toDateString())->toBe('2027-04-15');
    });

    it('does not overflow a short month', function () {
        $due = TaskSchedule::firstDueDate(
            cadence: Cadence::Monthly,
            from: Carbon::parse('2026-01-30'),
            anchorDay: 28,
            monthsAfterAnchor: 1,
        );

        expect($due->month)->toBe(2)
            ->and($due->day)->toBe(28);
    });
});

describe('advancing', function () {
    it('moves forward one period', function () {
        $schedule = TaskSchedule::factory()->create([
            'team_id' => $this->team->id,
            'cadence' => Cadence::Quarterly->value,
            'next_due_at' => '2026-09-01',
        ]);

        $schedule->advance(Carbon::parse('2026-09-22'));

        expect($schedule->next_due_at->toDateString())->toBe('2026-12-01')
            ->and($schedule->last_started_at)->not->toBeNull();
    });

    it('skips missed occurrences instead of catching up one at a time', function () {
        $schedule = TaskSchedule::factory()->create([
            'team_id' => $this->team->id,
            'cadence' => Cadence::Monthly->value,
            'next_due_at' => '2026-01-05',
        ]);

        // Nobody touched it for eight months; the next date should be ahead,
        // not still in the past.
        $schedule->advance(Carbon::parse('2026-09-22'));

        expect($schedule->next_due_at->toDateString())->toBe('2026-10-05')
            ->and($schedule->isOverdue(Carbon::parse('2026-09-22')))->toBeFalse();
    });
});

describe('the calendar', function () {
    it('lists what is due and what is overdue', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        TaskSchedule::factory()->overdue()->create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
        ]);
        TaskSchedule::factory()->create([
            'team_id' => $this->team->id,
            'skill_id' => Skill::factory()->create()->id,
        ]);

        $this->actingAs($this->user)
            ->get($this->base.'/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('schedules/Index')
                ->has('schedules', 2)
                ->where('counts.overdue', 1)
                ->where('counts.due', 1)
                ->where('schedules.0.isOverdue', true)
            );
    });

    it('adds a task to the calendar', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        $this->actingAs($this->user)
            ->post($this->base.'/calendar', [
                'skill' => $skill->slug,
                'cadence' => 'annually',
                'anchor_month' => 6,
                'anchor_day' => 15,
                'months_after_anchor' => 0,
            ])
            ->assertRedirect();

        expect(TaskSchedule::query()->sole()->next_due_at->format('m-d'))->toBe('06-15');
    });

    it('will not schedule the same task twice', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);
        TaskSchedule::factory()->create(['team_id' => $this->team->id, 'skill_id' => $skill->id]);

        $this->actingAs($this->user)
            ->post($this->base.'/calendar', [
                'skill' => $skill->slug,
                'cadence' => 'annually',
                'anchor_day' => 1,
                'months_after_anchor' => 0,
            ])
            ->assertSessionHasErrors('skill');

        expect(TaskSchedule::count())->toBe(1);
    });

    it('refuses a fiscal-year anchor before the year end is known', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        $this->actingAs($this->user)
            ->post($this->base.'/calendar', [
                'skill' => $skill->slug,
                'cadence' => 'annually',
                'anchored_to_fiscal_year_end' => true,
                'anchor_day' => 15,
                'months_after_anchor' => 4,
            ])
            ->assertSessionHasErrors('anchored_to_fiscal_year_end');
    });

    it('uses the recorded fiscal year end when there is one', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);
        OrganizationProfile::create([
            'team_id' => $this->team->id,
            'fiscal_year_end_month' => 6,
        ]);

        $this->actingAs($this->user)->post($this->base.'/calendar', [
            'skill' => $skill->slug,
            'cadence' => 'annually',
            'anchored_to_fiscal_year_end' => true,
            'anchor_day' => 15,
            'months_after_anchor' => 4,
        ]);

        expect(TaskSchedule::query()->sole()->next_due_at->format('m-d'))->toBe('10-15');
    });
});

describe('running from the calendar', function () {
    it('starts the work and moves the schedule on', function () {
        $skill = Skill::factory()->create();
        app(SyncTeamCatalogue::class)->handle($this->team);

        $schedule = TaskSchedule::factory()->due()->create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'cadence' => Cadence::Monthly->value,
        ]);
        $originalDue = $schedule->next_due_at->copy();

        $this->actingAs($this->user)
            ->post($this->base.'/calendar/'.$schedule->id.'/run')
            ->assertRedirect();

        $run = TaskRun::query()->sole();

        expect($run->task_schedule_id)->toBe($schedule->id)
            ->and($schedule->refresh()->next_due_at->gt($originalDue))->toBeTrue()
            ->and($schedule->last_started_at)->not->toBeNull();
    });

    it('does not touch another organization calendar', function () {
        $other = User::factory()->create();
        $schedule = TaskSchedule::factory()->create([
            'team_id' => $other->personalTeam()->id,
        ]);

        $this->actingAs($this->user)
            ->post($this->base.'/calendar/'.$schedule->id.'/run')
            ->assertNotFound();

        $this->actingAs($this->user)
            ->delete($this->base.'/calendar/'.$schedule->id)
            ->assertNotFound();

        expect(TaskSchedule::count())->toBe(1);
    });

    it('can be paused and removed', function () {
        $schedule = TaskSchedule::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->patch($this->base.'/calendar/'.$schedule->id, ['is_active' => false])
            ->assertRedirect();

        expect($schedule->refresh()->is_active)->toBeFalse()
            ->and(TaskSchedule::query()->due()->count())->toBe(0);

        $this->actingAs($this->user)
            ->delete($this->base.'/calendar/'.$schedule->id)
            ->assertRedirect();

        expect(TaskSchedule::count())->toBe(0);
    });
});
