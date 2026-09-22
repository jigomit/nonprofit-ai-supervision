<?php

namespace App\Http\Controllers;

use App\Enums\AiProvider;
use App\Enums\TeamPermission;
use App\Http\Requests\SaveAiSettingsRequest;
use App\Models\OrganizationProfile;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an organization points its drafts.
 *
 * Kept apart from the rest of the organization profile because it holds a
 * secret: the key is written but never read back to the browser, and the form
 * treats an empty key field as "leave it alone" rather than "erase it".
 */
class AiSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $team = $this->resolveTeam($request);
        $profile = $team->organizationProfile;

        return Inertia::render('organization/Ai', [
            'settings' => [
                'provider' => $profile?->ai_provider?->value,
                'model' => $profile?->ai_model,
                'baseUrl' => $profile?->ai_base_url,
                // The key itself never leaves the server. All the form needs
                // to know is whether one is already stored.
                'hasApiKey' => filled($profile?->ai_api_key),
                'isConfigured' => $profile?->hasAiConfigured() ?? false,
            ],
            'providers' => AiProvider::options(),
            'canEdit' => $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
        ]);
    }

    public function update(SaveAiSettingsRequest $request): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        abort_unless(
            $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
            403,
        );

        $profile = OrganizationProfile::firstOrNew(['team_id' => $team->id]);

        $profile->fill([
            'team_id' => $team->id,
            ...$request->settings(),
        ])->save();

        return back()->with('status', $profile->hasAiConfigured()
            ? 'Drafts will now be produced by '.$profile->ai_provider->label().'.'
            : 'Saved. Add a key before running a task.');
    }

    /**
     * Stop using the stored key without having to clear the whole profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        abort_unless(
            $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
            403,
        );

        $team->organizationProfile?->forceFill([
            'ai_provider' => null,
            'ai_model' => null,
            'ai_api_key' => null,
            'ai_base_url' => null,
        ])->save();

        return back()->with('status', 'Removed. Tasks will produce placeholder output until a provider is set.');
    }

    /**
     * Read the team from the route rather than the user's current team, which
     * the middleware may have changed under an already-loaded user.
     */
    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
