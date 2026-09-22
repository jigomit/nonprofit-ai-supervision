<?php

use App\Models\User;

it('renders the login page without javascript errors', function () {
    visit('/login')
        ->assertSee('Log in')
        ->assertNoJavascriptErrors();
});

it('reaches a team dashboard as a signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/'.$user->personalTeam()->slug.'/dashboard')
        ->assertNoJavascriptErrors();
});
