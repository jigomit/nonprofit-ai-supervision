<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Skill library source
    |--------------------------------------------------------------------------
    |
    | The catalogue is imported from a checkout of the open-source
    | sector-skills/nonprofit-skills library (MIT). `skills:import` reads every
    | SKILL.md beneath `path` and records the checkout's commit on each row, so
    | an audit record can always name the source revision it was built from.
    |
    */

    'path' => env('SKILLS_LIBRARY_PATH', storage_path('app/skills-library')),

    'repository' => env('SKILLS_LIBRARY_REPO', 'https://github.com/sector-skills/nonprofit-skills'),

    /*
    |--------------------------------------------------------------------------
    | Core categories
    |--------------------------------------------------------------------------
    |
    | The library splits into core categories that apply to every nonprofit and
    | "special collections" scoped to an organization type. Onboarding gives an
    | org every core category, then lets it opt into the collections that match
    | it, so anything not listed here is treated as a special collection.
    |
    */

    'core_categories' => [
        'advocacy-policy',
        'communications-marketing',
        'executive-leadership',
        'finance-operations',
        'fundraising-development',
        'governance-compliance',
        'programs-impact',
        'strategy-growth',
        'technology-data',
        'volunteer-people',
    ],

];
