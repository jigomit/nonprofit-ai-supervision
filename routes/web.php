<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationProfileController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('organization', [OrganizationProfileController::class, 'edit'])->name('organization.edit');
        Route::put('organization', [OrganizationProfileController::class, 'update'])->name('organization.update');

        Route::get('skills', [SkillController::class, 'index'])->name('skills.index');
        Route::get('skills/{skill}', [SkillController::class, 'show'])->name('skills.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
