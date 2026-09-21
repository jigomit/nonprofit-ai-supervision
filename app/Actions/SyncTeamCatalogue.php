<?php

namespace App\Actions;

use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Builds an organization's working catalogue from its profile.
 *
 * Every nonprofit gets the core categories. Special collections are opt-in, so
 * a food bank is not asked to reason about performance licensing and a theatre
 * is not asked about LIHTC compliance.
 *
 * Skills that stop applying are disabled rather than deleted: an organization
 * that turns a collection off and later back on should not silently lose the
 * stricter supervision overrides it had set.
 */
class SyncTeamCatalogue
{
    /**
     * @return array{enabled:int, disabled:int, added:int}
     */
    public function handle(Team $team, ?OrganizationProfile $profile = null): array
    {
        $profile = $profile ?? $team->organizationProfile;
        $collections = $profile?->enabledCollections() ?? [];

        $applicable = Skill::query()
            ->where(fn ($query) => $query
                ->where('is_core', true)
                ->orWhereIn('category', $collections))
            ->pluck('id');

        $existing = DB::table('team_skill')
            ->where('team_id', $team->id)
            ->pluck('enabled', 'skill_id');

        $added = 0;
        $enabled = 0;
        $disabled = 0;
        $now = now();
        $inserts = [];

        foreach ($applicable as $skillId) {
            if (! $existing->has($skillId)) {
                $inserts[] = [
                    'team_id' => $team->id,
                    'skill_id' => $skillId,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $added++;
            }

            $enabled++;
        }

        DB::transaction(function () use ($team, $applicable, $inserts, $now, &$disabled) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('team_skill')->insert($chunk);
            }

            // Re-enable anything that became applicable again.
            DB::table('team_skill')
                ->where('team_id', $team->id)
                ->whereIn('skill_id', $applicable)
                ->where('enabled', false)
                ->update(['enabled' => true, 'updated_at' => $now]);

            $disabled = DB::table('team_skill')
                ->where('team_id', $team->id)
                ->whereNotIn('skill_id', $applicable)
                ->where('enabled', true)
                ->update(['enabled' => false, 'updated_at' => $now]);
        });

        return [
            'enabled' => $enabled,
            'disabled' => $disabled,
            'added' => $added,
        ];
    }
}
