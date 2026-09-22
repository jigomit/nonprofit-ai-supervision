<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import { dashboard } from '@/routes';
import { show as skillShow } from '@/routes/skills';
import type { Team } from '@/types';

type SkillSummary = {
    slug: string;
    name: string;
    description: string;
    category: string;
    categoryLabel: string;
    isCore: boolean;
    supervision: string;
    supervisionLabel: string;
    supervisionNote: string;
    enabled: boolean;
};

type Props = {
    skills: SkillSummary[];
    libraryIsEmpty: boolean;
    catalogueSize: number;
    filters: {
        search: string;
        category: string;
        supervision: string;
        collection: string;
    };
    categories: {
        value: string;
        label: string;
        isCore: boolean;
        total: number;
    }[];
    supervisionLevels: {
        value: string;
        label: string;
        gate: string;
        total: number;
    }[];
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const teamSlug = computed(() => props.currentTeam?.slug ?? '');

const search = ref(props.filters.search);
const category = ref(props.filters.category);
const supervision = ref(props.filters.supervision);
const collection = ref(props.filters.collection);

const coreCategories = computed(() =>
    props.categories.filter((entry) => entry.isCore),
);
const specialCategories = computed(() =>
    props.categories.filter((entry) => !entry.isCore),
);

const hasFilters = computed(
    () =>
        search.value !== '' ||
        category.value !== '' ||
        supervision.value !== '' ||
        collection.value !== '',
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const applyFilters = () => {
    router.get(
        window.location.pathname,
        {
            search: search.value || undefined,
            category: category.value || undefined,
            supervision: supervision.value || undefined,
            collection: collection.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});

watch([category, supervision, collection], applyFilters);

const clearFilters = () => {
    search.value = '';
    category.value = '';
    supervision.value = '';
    collection.value = '';
};

const selectClasses =
    'h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Skill catalogue', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="Skill catalogue" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Skill catalogue"
            description="Every task in the library, with the level of human review it needs before its output can be used."
        />

        <!-- The tier counts double as filters: the distribution is the headline,
             and clicking a band is the fastest way to see what sits behind it. -->
        <div class="grid gap-3 sm:grid-cols-3">
            <button
                v-for="level in supervisionLevels"
                :key="level.value"
                type="button"
                class="flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition-colors hover:bg-accent/50"
                :class="
                    supervision === level.value
                        ? 'border-ring ring-[3px] ring-ring/20'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
                :aria-pressed="supervision === level.value"
                @click="
                    supervision = supervision === level.value ? '' : level.value
                "
            >
                <div class="flex w-full items-center justify-between gap-2">
                    <SupervisionBadge
                        :level="level.value"
                        :label="level.label"
                        size="md"
                    />
                    <span class="text-2xl font-semibold tabular-nums">{{
                        level.total
                    }}</span>
                </div>
                <p class="text-xs text-muted-foreground">{{ level.gate }}</p>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-56 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    id="skill-search"
                    v-model="search"
                    type="search"
                    placeholder="Search tasks, deliverables, descriptions…"
                    class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                />
            </div>

            <select
                id="skill-collection"
                v-model="collection"
                :class="selectClasses"
                aria-label="Collection"
            >
                <option value="">All collections</option>
                <option value="core">Core categories</option>
                <option value="special">Special collections</option>
            </select>

            <select
                id="skill-category"
                v-model="category"
                :class="selectClasses"
                aria-label="Category"
            >
                <option value="">All categories</option>
                <optgroup label="Core">
                    <option
                        v-for="entry in coreCategories"
                        :key="entry.value"
                        :value="entry.value"
                    >
                        {{ entry.label }} ({{ entry.total }})
                    </option>
                </optgroup>
                <optgroup label="Special collections">
                    <option
                        v-for="entry in specialCategories"
                        :key="entry.value"
                        :value="entry.value"
                    >
                        {{ entry.label }} ({{ entry.total }})
                    </option>
                </optgroup>
            </select>

            <button
                v-if="hasFilters"
                type="button"
                class="h-9 rounded-md px-3 text-sm text-muted-foreground underline-offset-4 hover:underline"
                @click="clearFilters"
            >
                Clear
            </button>
        </div>

        <p class="text-sm text-muted-foreground">
            Showing {{ skills.length }}
            {{ skills.length === 1 ? 'task' : 'tasks' }}
        </p>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="skill in skills"
                :key="skill.slug"
                :href="
                    skillShow({ current_team: teamSlug, skill: skill.slug }).url
                "
                class="flex flex-col gap-3 rounded-xl border p-4 transition-colors hover:bg-accent/50"
                :class="
                    skill.enabled
                        ? 'border-sidebar-border/70 dark:border-sidebar-border'
                        : 'border-dashed border-sidebar-border/50'
                "
            >
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-sm font-semibold text-balance">
                        {{ skill.name }}
                    </h2>
                    <SupervisionBadge
                        :level="skill.supervision"
                        :label="skill.supervisionLabel"
                    />
                </div>
                <p class="line-clamp-3 text-xs text-muted-foreground">
                    {{ skill.description }}
                </p>
                <div
                    class="mt-auto flex items-center gap-2 text-[11px] text-muted-foreground"
                >
                    <span>{{ skill.categoryLabel }}</span>
                    <span v-if="!skill.isCore" aria-hidden="true">·</span>
                    <span v-if="!skill.isCore">Special collection</span>
                    <!-- The card is still worth reading; it just cannot be
                         run, and finding that out only after clicking is the
                         catalogue breaking its own promise. -->
                    <span v-if="!skill.enabled" aria-hidden="true">·</span>
                    <span v-if="!skill.enabled" class="font-medium"
                        >Not in your catalogue</span
                    >
                </div>
            </Link>
        </div>

        <!-- An empty database and an over-narrow filter look identical on
             screen and have nothing to do with each other. -->
        <div
            v-if="libraryIsEmpty"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="text-sm font-medium">
                The task library has not been imported
            </p>
            <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                Signoff reads its tasks from the open-source nonprofit skills
                library. Whoever set this up needs to clone it and run
                <code class="rounded bg-muted px-1 py-0.5"
                    >php artisan skills:import</code
                >.
            </p>
        </div>

        <div
            v-else-if="skills.length === 0"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="text-sm font-medium">No tasks match those filters</p>
            <p class="mt-1 text-xs text-muted-foreground">
                Try a broader search, or clear the filters to see the whole
                catalogue.
            </p>
        </div>
    </div>
</template>
