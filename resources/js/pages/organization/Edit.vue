<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OrganizationProfileController from '@/actions/App/Http/Controllers/OrganizationProfileController';
import { dashboard } from '@/routes';
import type { Team } from '@/types';

type Props = {
    profile: {
        entityType: string | null;
        ein: string | null;
        stateOfIncorporation: string | null;
        fiscalYearEndMonth: number | null;
        budgetBand: string | null;
        mission: string | null;
        expertGatePolicy: string;
        collections: string[];
        onboardedAt: string | null;
    };
    budgetBands: { value: string; label: string }[];
    expertGatePolicies: {
        value: string;
        label: string;
        description: string;
    }[];
    availableCollections: { value: string; label: string; total: number }[];
    coreSkillCount: number;
    canEdit: boolean;
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const form = useForm({
    entity_type: props.profile.entityType ?? '',
    ein: props.profile.ein ?? '',
    state_of_incorporation: props.profile.stateOfIncorporation ?? '',
    fiscal_year_end_month: props.profile.fiscalYearEndMonth ?? null,
    budget_band: props.profile.budgetBand ?? '',
    mission: props.profile.mission ?? '',
    expert_gate_policy: props.profile.expertGatePolicy,
    collections: [...props.profile.collections],
});

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

// Supervision needs two people. Below roughly $1M an organization usually has
// one to five staff, so say so plainly at onboarding rather than selling a gate
// it cannot staff.
const smallOrgWarning = computed(
    () =>
        form.budget_band === 'under_250k' || form.budget_band === '250k_to_1m',
);

const catalogueSize = computed(() => {
    const extra = props.availableCollections
        .filter((collection) => form.collections.includes(collection.value))
        .reduce((total, collection) => total + collection.total, 0);

    return props.coreSkillCount + extra;
});

const submit = () => {
    form.put(
        OrganizationProfileController.update.url(props.currentTeam?.slug ?? ''),
        { preserveScroll: true },
    );
};

const selectClasses =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Organization', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="Organization" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <Heading
            title="Organization"
            description="What kind of nonprofit this is, which work applies to it, and who has to sign off before that work can be used."
        />

        <form class="flex max-w-3xl flex-col gap-8" @submit.prevent="submit">
            <fieldset :disabled="!canEdit" class="contents">
                <section class="flex flex-col gap-4">
                    <h2 class="text-sm font-semibold">Details</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="entity_type">Entity type</Label>
                            <Input
                                id="entity_type"
                                v-model="form.entity_type"
                                placeholder="501(c)(3) public charity"
                            />
                            <InputError :message="form.errors.entity_type" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="ein">EIN</Label>
                            <Input
                                id="ein"
                                v-model="form.ein"
                                placeholder="12-3456789"
                                inputmode="numeric"
                            />
                            <InputError :message="form.errors.ein" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="state_of_incorporation"
                                >State of incorporation</Label
                            >
                            <Input
                                id="state_of_incorporation"
                                v-model="form.state_of_incorporation"
                                placeholder="OH"
                                maxlength="2"
                                class="uppercase"
                            />
                            <InputError
                                :message="form.errors.state_of_incorporation"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fiscal_year_end_month"
                                >Fiscal year ends</Label
                            >
                            <select
                                id="fiscal_year_end_month"
                                v-model="form.fiscal_year_end_month"
                                :class="selectClasses"
                            >
                                <option :value="null">Not set</option>
                                <option
                                    v-for="(month, index) in months"
                                    :key="month"
                                    :value="index + 1"
                                >
                                    {{ month }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                Recurring work is scheduled against this.
                            </p>
                            <InputError
                                :message="form.errors.fiscal_year_end_month"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="budget_band">Annual budget</Label>
                            <select
                                id="budget_band"
                                v-model="form.budget_band"
                                :class="selectClasses"
                            >
                                <option value="">Prefer not to say</option>
                                <option
                                    v-for="band in budgetBands"
                                    :key="band.value"
                                    :value="band.value"
                                >
                                    {{ band.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.budget_band" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="mission">Mission</Label>
                        <textarea
                            id="mission"
                            v-model="form.mission"
                            rows="3"
                            placeholder="What the organization does, in a sentence or two."
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                        />
                        <p class="text-xs text-muted-foreground">
                            Passed to every task as context, so it is worth
                            being specific.
                        </p>
                        <InputError :message="form.errors.mission" />
                    </div>

                    <div
                        v-if="smallOrgWarning"
                        class="flex gap-3 rounded-lg border border-amber-600/25 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        <p>
                            Review gates need a second person. At this size most
                            organizations have one to five staff, so work
                            needing review may sit waiting. You can still use
                            the catalogue — just be deliberate about who
                            reviews.
                        </p>
                    </div>
                </section>

                <section class="flex flex-col gap-4">
                    <div>
                        <h2 class="text-sm font-semibold">
                            When work needs a credentialed professional
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Some tasks produce work that is filed with an
                            outside authority or legally binds the organization.
                            Choose what happens when no CPA or attorney is
                            available.
                        </p>
                    </div>

                    <div class="grid gap-3">
                        <label
                            v-for="policy in expertGatePolicies"
                            :key="policy.value"
                            class="flex cursor-pointer gap-3 rounded-xl border p-4 transition-colors hover:bg-accent/40"
                            :class="
                                form.expert_gate_policy === policy.value
                                    ? 'border-ring ring-[3px] ring-ring/20'
                                    : 'border-sidebar-border/70 dark:border-sidebar-border'
                            "
                        >
                            <input
                                v-model="form.expert_gate_policy"
                                type="radio"
                                name="expert_gate_policy"
                                :value="policy.value"
                                class="mt-1 size-4 shrink-0"
                            />
                            <span class="flex flex-col gap-1">
                                <span class="text-sm font-medium">{{
                                    policy.label
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    policy.description
                                }}</span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.expert_gate_policy" />
                </section>

                <section class="flex flex-col gap-4">
                    <div>
                        <h2 class="text-sm font-semibold">
                            Special collections
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Every organization gets the
                            {{ coreSkillCount }} core tasks. Add only the
                            collections that match what you actually do.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="collection in availableCollections"
                            :key="collection.value"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors hover:bg-accent/40"
                            :class="
                                form.collections.includes(collection.value)
                                    ? 'border-ring ring-[3px] ring-ring/20'
                                    : 'border-sidebar-border/70 dark:border-sidebar-border'
                            "
                        >
                            <input
                                v-model="form.collections"
                                type="checkbox"
                                :value="collection.value"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <span class="flex flex-col">
                                <span class="text-sm font-medium">{{
                                    collection.label
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >{{ collection.total }} tasks</span
                                >
                            </span>
                        </label>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Your catalogue:
                        <span class="font-medium text-foreground tabular-nums"
                            >{{ catalogueSize }} tasks</span
                        >
                    </p>
                    <InputError :message="form.errors.collections" />
                </section>
            </fieldset>

            <div v-if="canEdit" class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save organization' }}
                </Button>
                <p
                    v-if="form.recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Saved.
                </p>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Only an owner or admin can change these settings.
            </p>
        </form>
    </div>
</template>
