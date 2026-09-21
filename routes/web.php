<?php

use App\Http\Controllers\BoardReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpertInvitationController;
use App\Http\Controllers\OrganizationProfileController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TaskDecisionController;
use App\Http\Controllers\TaskRunController;
use App\Http\Controllers\TaskScheduleController;
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

        Route::get('tasks', [TaskRunController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [TaskRunController::class, 'store'])->name('tasks.store');
        Route::get('tasks/{taskRun}', [TaskRunController::class, 'show'])->name('tasks.show');
        Route::post('tasks/{taskRun}/decision', [TaskDecisionController::class, 'store'])->name('tasks.decision');
        Route::post('tasks/{taskRun}/expert-invitation', [ExpertInvitationController::class, 'store'])->name('tasks.invite-expert');

        Route::get('calendar', [TaskScheduleController::class, 'index'])->name('schedules.index');
        Route::post('calendar', [TaskScheduleController::class, 'store'])->name('schedules.store');
        Route::patch('calendar/{schedule}', [TaskScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('calendar/{schedule}', [TaskScheduleController::class, 'destroy'])->name('schedules.destroy');
        Route::post('calendar/{schedule}/run', [TaskScheduleController::class, 'run'])->name('schedules.run');

        Route::get('report', [BoardReportController::class, 'index'])->name('report.index');
        Route::get('report/export', [BoardReportController::class, 'export'])->name('report.export');
        Route::post('report/changes/{change}', [BoardReportController::class, 'acknowledge'])->name('report.acknowledge');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

// Reached by link, without an account: an outside professional clearing one
// gate for one piece of work.
Route::get('expert-review/{invitation}', [ExpertInvitationController::class, 'show'])->name('expert.review');
Route::post('expert-review/{invitation}', [TaskDecisionController::class, 'storeFromInvitation'])
    ->middleware('throttle:10,1')
    ->name('expert.decision');

require __DIR__.'/settings.php';
