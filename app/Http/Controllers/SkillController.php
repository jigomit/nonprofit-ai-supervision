<?php

namespace App\Http\Controllers;

use App\Enums\SupervisionLevel;
use App\Models\Skill;
use App\Models\Team;
use App\Models\TeamSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'supervision' => (string) $request->query('supervision', ''),
            'collection' => (string) $request->query('collection', ''),
        ];

        $skills = Skill::query()
            ->when($filters['search'] !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('description', 'like', '%'.$filters['search'].'%')
                    ->orWhere('slug', 'like', '%'.$filters['search'].'%')
            ))
            ->when($filters['category'] !== '', fn ($query) => $query->where('category', $filters['category']))
            ->when(
                SupervisionLevel::tryFrom($filters['supervision']) !== null,
                fn ($query) => $query->where('supervision', $filters['supervision'])
            )
            ->when($filters['collection'] === 'core', fn ($query) => $query->where('is_core', true))
            ->when($filters['collection'] === 'special', fn ($query) => $query->where('is_core', false))
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Skill $skill) => $this->summarise($skill));

        return Inertia::render('skills/Index', [
            'skills' => $skills,
            'filters' => $filters,
            // An aggregate, not a list of skills, so it runs on the base query
            // builder rather than hydrating models with a phantom `total`.
            'categories' => DB::table('skills')
                ->selectRaw('category, is_core, count(*) as total')
                ->groupBy('category', 'is_core')
                ->orderBy('category')
                ->get()
                ->map(fn (object $row) => [
                    'value' => $row->category,
                    'label' => Str::headline((string) $row->category),
                    'isCore' => (bool) $row->is_core,
                    'total' => (int) $row->total,
                ]),
            'supervisionLevels' => collect(SupervisionLevel::cases())
                ->map(fn (SupervisionLevel $level) => [
                    'value' => $level->value,
                    'label' => $level->label(),
                    'gate' => $level->gate(),
                    'total' => Skill::query()->supervision($level)->count(),
                ]),
        ]);
    }

    /**
     * The team slug is resolved by middleware rather than bound here, but it is
     * still the first route parameter, so it has to be declared to keep the
     * skill in the right position.
     */
    public function show(string $currentTeam, Skill $skill): Response
    {
        $skill->load('relatedSkills');

        // Whether this organization can actually run it, which decides between
        // showing the run control and explaining why it is absent.
        $team = Team::query()->where('slug', $currentTeam)->firstOrFail();

        $enabled = TeamSkill::query()
            ->where('team_id', $team->id)
            ->where('skill_id', $skill->id)
            ->where('enabled', true)
            ->exists();

        return Inertia::render('skills/Show', [
            'enabled' => $enabled,
            'skill' => [
                ...$this->summarise($skill),
                'body' => Str::markdown($skill->body),
                // Lifted out of the body so they can be read before the run
                // rather than only after a reviewer finds the same mistake.
                'failureModes' => array_map(
                    fn (string $item) => Str::markdown($item),
                    $skill->failure_modes ?? [],
                ),
                'deliverables' => array_map(
                    fn (string $item) => Str::markdown($item),
                    $skill->deliverables ?? [],
                ),
                'tokenEstimate' => $skill->token_estimate,
                'dateAdded' => $skill->date_added?->toDateString(),
                'lastReviewed' => $skill->last_reviewed?->toDateString(),
                'license' => $skill->license,
                'sourceCommit' => $skill->source_commit,
                'related' => $skill->relatedSkills->map(fn (Skill $related) => [
                    'slug' => $related->slug,
                    'name' => $related->name,
                    'supervision' => $related->supervision->value,
                    'supervisionLabel' => $related->supervision->label(),
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarise(Skill $skill): array
    {
        return [
            'slug' => $skill->slug,
            'name' => $skill->name,
            'description' => $skill->description,
            'category' => $skill->category,
            'categoryLabel' => Str::headline($skill->category),
            'isCore' => $skill->is_core,
            'supervision' => $skill->supervision->value,
            'supervisionLabel' => $skill->supervision->label(),
            'supervisionGate' => $skill->supervision->gate(),
            'supervisionNote' => $skill->supervision_note,
        ];
    }
}
