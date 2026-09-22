<?php

use App\Models\User;

it('opens the landing page and leads with the gate', function () {
    visit('/')
        ->assertSee('Very few can say who checked it')
        ->assertSee('expert-required')
        ->assertSee('Nonprofit AI Skills Library')
        ->assertSee('Brendon Connelly')
        ->assertNoJavascriptErrors();
});

it('gets a visitor from the landing page to sign in', function () {
    visit('/')
        ->click('Sign in')
        ->assertSee('Log in')
        ->assertNoJavascriptErrors();
});

it('explains a missing page instead of showing a stack trace', function () {
    visit('/no-such-page')
        ->assertSee('There is nothing at this address')
        ->assertSee('Nothing has broken')
        ->assertDontSee('Exception')
        ->assertNoJavascriptErrors();
});

it('offers a way back from an address that matched nothing', function () {
    // An unmatched URL never reaches route middleware, so there is no current
    // team to send anyone back to. The start page is the honest destination.
    $this->actingAs(User::factory()->create());

    visit('/no-such-page')
        ->assertSee('Back to the start')
        ->click('Back to the start')
        ->assertSee('Very few can say who checked it')
        ->assertNoJavascriptErrors();
});

it('explains a page that belongs to someone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    // Inside the app the middleware has run, so this one can offer the way
    // back that an unmatched URL cannot.
    visit('/'.$other->personalTeam()->slug.'/dashboard')
        ->assertSee('This is not yours to open')
        ->assertSee('Back to your dashboard')
        ->assertNoJavascriptErrors();
});
