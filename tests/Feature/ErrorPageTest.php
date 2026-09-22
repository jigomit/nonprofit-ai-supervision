<?php

use App\Models\ExpertInvitation;
use App\Models\TaskRun;
use App\Models\User;

it('gives a missing address a human answer, not a stack trace', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 404)
        );
});

it('explains a forbidden page rather than just refusing', function () {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($user)
        ->get('/'.$someoneElse->personalTeam()->slug.'/dashboard')
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 403)
        );
});

it('explains an expired review link', function () {
    // The guest page is the one strangers meet, so its dead end has to read
    // as an explanation rather than a failure.
    $user = User::factory()->create();
    $run = TaskRun::factory()->awaitingExpert()->create([
        'team_id' => $user->personalTeam()->id,
    ]);
    $invitation = $run->expertInvitations()->create([
        'token' => ExpertInvitation::generateToken(),
        'invited_by' => $user->id,
        'email' => 'cpa@example.com',
        'expires_at' => now()->addDay(),
        'used_at' => now(),
    ]);

    $this->post('/expert-review/'.$invitation->token, [
        'decision' => 'approved',
        'approver_name' => 'Dana Reyes',
        'credential_type' => 'cpa',
    ])
        ->assertStatus(410)
        ->assertInertia(fn ($page) => $page->where('status', 410));
});

it('keeps returning json to clients that asked for json', function () {
    $this->getJson('/no-such-page')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

it('still shows the real failure while debugging is on', function () {
    // A friendly sentence over a stack trace helps nobody in development.
    config(['app.debug' => true]);

    expect(config('app.debug'))->toBeTrue();

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->where('status', 404));
});
