<?php

namespace App\Http\Controllers;

use App\Actions\SyncTeamCatalogue;
use App\Enums\BudgetBand;
use App\Enums\ExpertGatePolicy;
use App\Enums\TeamPermission;
use App\Http\Requests\SaveOrganizationProfileRequest;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $team = $this->resolveTeam($request);
        $profile = $team->organizationProfile;

        // An organization that has not been through onboarding yet has no
        // profile row, so the form opens on the default policy.
        $policy = $profile !== null
            ? $profile->expert_gate_policy
            : ExpertGatePolicy::default();

        return Inertia::render('organization/Edit', [
            'profile' => [
                'entityType' => $profile?->entity_type,
                'ein' => $profile?->ein,
                'stateOfIncorporation' => $profile?->state_of_incorporation,
                'fiscalYearEndMonth' => $profile?->fiscal_year_end_month,
                'budgetBand' => $profile?->budget_band?->value,
                'mission' => $profile?->mission,
                'expertGatePolicy' => $policy->value,
                'collections' => $profile?->enabledCollections() ?? [],
                'onboardedAt' => $profile?->onboarded_at?->toIso8601String(),
            ],
            'budgetBands' => BudgetBand::options(),
            'expertGatePolicies' => array_map(
                fn (ExpertGatePolicy $policy) => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                    'description' => $policy->description(),
                ],
                ExpertGatePolicy::cases(),
            ),
            'availableCollections' => $this->availableCollections(),
            'coreSkillCount' => Skill::query()->where('is_core', true)->count(),
            'canEdit' => $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
        ]);
    }

    public function update(SaveOrganizationProfileRequest $request, SyncTeamCatalogue $sync): RedirectResponse
    {
        $team = $this->resolveTeam($request);

        abort_unless(
            $request->user()->hasTeamPermission($team, TeamPermission::UpdateTeam),
            403,
        );

        $existing = $team->organizationProfile;

        // The first save is what marks the organization as onboarded; later
        // edits must not move the date.
        $onboardedAt = $existing !== null ? $existing->onboarded_at : null;

        $profile = OrganizationProfile::updateOrCreate(
            ['team_id' => $team->id],
            [
                ...$request->validated(),
                'onboarded_at' => $onboardedAt ?? now(),
            ],
        );

        $result = $sync->handle($team, $profile->refresh());

        return back()->with('status', sprintf(
            '%d tasks are now in your catalogue.',
            $result['enabled'],
        ));
    }

    /**
     * The special collections an organization can opt into, with what each one
     * would add. Derived from the catalogue rather than hardcoded so a new
     * upstream collection appears without a code change.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function availableCollections(): array
    {
        return DB::table('skills')
            ->where('is_core', false)
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn (object $row) => [
                'value' => $row->category,
                'label' => Str::headline((string) $row->category),
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Read the team from the route rather than the user's current team. The
     * middleware switches `current_team` as a side effect, so the relation on
     * an already-loaded user can be stale; the URL never is.
     */
    protected function resolveTeam(Request $request): Team
    {
        return Team::query()
            ->where('slug', $request->route('current_team'))
            ->firstOrFail();
    }
}
