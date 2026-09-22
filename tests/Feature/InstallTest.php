<?php

use App\Console\Commands\InstallSignoff;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-up is closed by default and invitations need somebody to send them, so
 * without this a fresh install has no way in at all.
 */
uses(RefreshDatabase::class);

it('creates the first owner and their organization', function () {
    $this->artisan('signoff:install', ['--name' => 'Ada Bright', '--email' => 'ada@example.org'])
        ->expectsQuestion('Choose a password', 'a-long-enough-password')
        ->expectsQuestion('What is the organization called?', 'Akron Family Services')
        ->assertSuccessful();

    $user = User::sole();
    $team = Team::sole();

    expect($user->name)->toBe('Ada Bright')
        ->and($user->email)->toBe('ada@example.org')
        ->and(Hash::check('a-long-enough-password', $user->password))->toBeTrue()
        ->and($team->name)->toBe('Akron Family Services')
        // The same shape registration produces, not a special one.
        ->and($user->fresh()->personalTeam()->id)->toBe($team->id)
        ->and($team->memberships()->sole()->role)->toBe(TeamRole::Owner);
});

it('refuses once anybody has an account', function () {
    User::factory()->create();

    $this->artisan('signoff:install', ['--name' => 'Second', '--email' => 'second@example.org'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('never takes a password on the command line', function () {
    // A password in argv is a password in the shell history.
    expect((new ReflectionClass(InstallSignoff::class))
        ->getDefaultProperties()['signature'])
        ->not->toContain('password');
});
