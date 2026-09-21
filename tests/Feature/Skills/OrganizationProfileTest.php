<?php

use App\Enums\ExpertGatePolicy;
use App\Enums\TeamRole;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->url = '/'.$this->team->slug.'/organization';
});

function validProfile(array $overrides = []): array
{
    return array_merge([
        'entity_type' => '501(c)(3) public charity',
        'ein' => '12-3456789',
        'state_of_incorporation' => 'oh',
        'fiscal_year_end_month' => 6,
        'budget_band' => '1m_to_5m',
        'mission' => 'Housing support for families in Akron.',
        'expert_gate_policy' => 'override_with_justification',
        'collections' => [],
    ], $overrides);
}

it('requires authentication', function () {
    $this->get($this->url)->assertRedirect('/login');
});

it('shows the profile form with the available collections', function () {
    Skill::factory()->count(3)->create();
    Skill::factory()->specialCollection('faith-based')->count(2)->create();

    $this->actingAs($this->user)
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/Edit')
            ->where('coreSkillCount', 3)
            ->has('availableCollections', 1)
            ->where('availableCollections.0.value', 'faith-based')
            ->where('availableCollections.0.total', 2)
            ->where('profile.expertGatePolicy', 'override_with_justification')
            ->where('canEdit', true)
        );
});

it('saves the profile and builds the catalogue', function () {
    Skill::factory()->count(4)->create();
    Skill::factory()->specialCollection('arts-culture')->count(6)->create();

    $this->actingAs($this->user)
        ->put($this->url, validProfile(['collections' => ['arts-culture']]))
        ->assertRedirect();

    $profile = OrganizationProfile::query()->sole();

    expect($profile->team_id)->toBe($this->team->id)
        ->and($profile->ein)->toBe('12-3456789')
        ->and($profile->state_of_incorporation)->toBe('OH')
        ->and($profile->expert_gate_policy)->toBe(ExpertGatePolicy::OverrideWithJustification)
        ->and($profile->collections)->toBe(['arts-culture'])
        ->and($profile->onboarded_at)->not->toBeNull()
        ->and($this->team->enabledSkills()->count())->toBe(10);
});

it('does not move the onboarding date on a later edit', function () {
    Skill::factory()->create();

    $this->actingAs($this->user)->put($this->url, validProfile());
    $first = OrganizationProfile::query()->sole()->onboarded_at;

    $this->travel(2)->days();

    $this->actingAs($this->user)->put($this->url, validProfile(['mission' => 'Updated.']));
    $profile = OrganizationProfile::query()->sole();

    expect($profile->onboarded_at->toIso8601String())->toBe($first->toIso8601String())
        ->and($profile->mission)->toBe('Updated.');
});

it('rejects a malformed EIN and an unknown collection', function () {
    Skill::factory()->specialCollection('faith-based')->create();

    $this->actingAs($this->user)
        ->put($this->url, validProfile(['ein' => '123456789']))
        ->assertSessionHasErrors('ein');

    $this->actingAs($this->user)
        ->put($this->url, validProfile(['collections' => ['not-a-collection']]))
        ->assertSessionHasErrors('collections.0');

    expect(OrganizationProfile::count())->toBe(0);
});

it('rejects opting into a core category as if it were a collection', function () {
    Skill::factory()->create(['category' => 'finance-operations', 'is_core' => true]);

    $this->actingAs($this->user)
        ->put($this->url, validProfile(['collections' => ['finance-operations']]))
        ->assertSessionHasErrors('collections.0');
});

it('requires an expert gate policy', function () {
    $this->actingAs($this->user)
        ->put($this->url, validProfile(['expert_gate_policy' => '']))
        ->assertSessionHasErrors('expert_gate_policy');
});

it('lets a member view but not change the organization', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEdit', false));

    $this->actingAs($member)
        ->put($this->url, validProfile())
        ->assertForbidden();

    expect(OrganizationProfile::count())->toBe(0);
});

it('does not expose another team organization', function () {
    $otherTeam = User::factory()->create()->personalTeam();

    $this->actingAs($this->user)
        ->get('/'.$otherTeam->slug.'/organization')
        ->assertForbidden();
});
