<?php

use App\Actions\StartTaskRun;
use App\Actions\SyncTeamCatalogue;
use App\Enums\TeamRole;
use App\Exceptions\GateViolation;
use App\Jobs\RunTaskJob;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * The two things that make this safe to put on a reachable host: nobody can
 * give themselves an account, and no organization can spend its key without
 * limit.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();

    $this->skill = Skill::factory()->create();
    app(SyncTeamCatalogue::class)->handle($this->team);
});

function startOne(): TaskRun
{
    return app(StartTaskRun::class)->handle(
        test()->team,
        test()->skill,
        test()->user,
        ['notes' => 'Draft it.'],
    );
}

it('stops an organization at its daily allowance', function () {
    config(['ai.daily_run_limit' => 3]);

    startOne();
    startOne();
    startOne();

    expect(fn () => startOne())
        ->toThrow(GateViolation::class, 'started its 3 runs for today');

    expect(TaskRun::count())->toBe(3);
});

it('writes no run when it refuses one', function () {
    config(['ai.daily_run_limit' => 1]);

    startOne();

    try {
        startOne();
    } catch (GateViolation) {
        // The point is what is left behind, not the message.
    }

    expect(TaskRun::count())->toBe(1);
    Queue::assertPushed(RunTaskJob::class, 1);
});

it('counts yesterday against yesterday', function () {
    config(['ai.daily_run_limit' => 2]);

    TaskRun::factory()->count(5)->for($this->team)->for($this->skill)->create([
        'created_at' => now()->subDay(),
    ]);

    expect(fn () => startOne())->not->toThrow(GateViolation::class);
});

it('lets one organization have a number of its own', function () {
    config(['ai.daily_run_limit' => 1]);

    OrganizationProfile::create([
        'team_id' => $this->team->id,
        'daily_run_limit' => 4,
    ]);

    // The relation was resolved before the profile existed.
    $this->team->unsetRelation('organizationProfile');

    startOne();
    startOne();
    startOne();
    startOne();

    expect(fn () => startOne())->toThrow(GateViolation::class, 'started its 4 runs');
});

it('removes the cap at zero', function () {
    config(['ai.daily_run_limit' => 0]);

    foreach (range(1, 6) as $ignored) {
        startOne();
    }

    expect(TaskRun::count())->toBe(6);
});

it('caps the whole organization, not each person', function () {
    config(['ai.daily_run_limit' => 2]);

    $mate = User::factory()->create();
    $this->team->members()->attach($mate, ['role' => TeamRole::Member->value]);

    startOne();
    app(StartTaskRun::class)->handle($this->team, $this->skill, $mate, []);

    expect(fn () => startOne())->toThrow(GateViolation::class);
});

it('surfaces the refusal on the work list rather than breaking', function () {
    config(['ai.daily_run_limit' => 1]);

    startOne();

    $this->actingAs($this->user)
        ->post('/'.$this->team->slug.'/tasks', ['skill' => $this->skill->slug])
        ->assertSessionHasErrors();
});

it('offers a sign-up link where sign-up is open', function () {
    // The suite runs with ALLOW_REGISTRATION on, because the auth tests need a
    // door to walk through. config/signoff.php defaults it closed.
    config(['signoff.registration' => true]);

    $this->get(route('login'))->assertInertia(
        fn ($page) => $page->component('auth/Login')->where('canRegister', true),
    );
});

it('turns away a sign-up the host has not opened', function () {
    config(['signoff.registration' => false]);

    $this->get(route('register'))->assertForbidden();

    $this->post(route('register.store'), [
        'name' => 'Uninvited',
        'email' => 'uninvited@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertForbidden();

    expect(User::where('email', 'uninvited@example.com')->exists())->toBeFalse();
});

it('hides the sign-up link when the door is closed', function () {
    config(['signoff.registration' => false]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('canRegister', false));
});

it('leaves the route registered either way, so one bundle serves both', function () {
    // Wayfinder generates the frontend from live routes. A route that exists
    // only in development produces a bundle production cannot build.
    config(['signoff.registration' => false]);

    expect(Route::has('register'))->toBeTrue()
        ->and(Route::has('register.store'))->toBeTrue();
});
